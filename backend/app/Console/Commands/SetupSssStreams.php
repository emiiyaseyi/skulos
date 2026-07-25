<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Score;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\SubjectRemark;
use App\Models\Tenant\TeachingAssignment;
use App\Services\Tenancy\TenantManager;
use App\Support\ElectiveGroups;
use Illuminate\Console\Command;

/**
 * Splits every Senior Secondary class (SSS 1-3) into its 3 real streams,
 * Science & Technology / Arts & Humanities / Commercial (user directive
 * 2026-07-23, live report on greenfield: "in SSS, all subjects are not
 * done by the all students as we have 3 streams per class... the only
 * mandatory subjects are Maths, English and Civic... update the classes
 * streams in Greenfield for SSS to have the three streams, set the
 * mandatory subjects and divide the students into the three streams per
 * class"). Not a one-off script: reusable and re-runnable like every
 * other real-data command in this codebase (schools:seed-demo-data,
 * timetable:populate), and idempotent, a second run on an already split
 * school changes nothing.
 *
 * What this actually does, per matched class:
 * 1. The class's own subject list is trimmed to just its mandatory
 *    subjects (Mathematics/English Language/Civic Education) plus the
 *    non-stream-specific optional ones (Digital Technologies/Trade
 *    Subject), read from config('skulos.default_subjects.ss') — every
 *    stream inherits this shared list via ClassArm::offeredSubjects().
 * 2. 3 real ClassArm streams exist: the class's own existing single arm
 *    is reused (renamed, never deleted, the same "convert the default
 *    stream in place" pattern AcademicsController::storeArm() already
 *    uses) for the first stream, the other 2 are created fresh.
 * 3. Each stream's own subject list, from
 *    config('skulos.sss_stream_subjects'), is attached at the class arm
 *    level (mandatory, examinable), on top of the shared list above.
 * 4. Students on the class are split evenly across the 3 streams (round
 *    robin by admission number, deterministic and repeatable).
 * 5. Every moved student's own Score rows are re-pointed to their new
 *    stream's class_arm_id, and any score for a subject no longer on
 *    their own stream's list (the very bug this command exists to fix,
 *    a subject teacher clearly outside a student's real stream having a
 *    score on file) is removed, never left as stale data pretending to
 *    still be real.
 * 6. Every stream gets its own TeachingAssignment for each of its
 *    subjects, copied from whichever staff member already taught that
 *    subject on the class's original single arm, so score entry keeps
 *    working for a scoped subject/class teacher, not just a blanket
 *    access admin.
 */
class SetupSssStreams extends Command
{
    protected $signature = 'sss:setup-streams {school}';

    protected $description = 'Splits every Senior Secondary class into its Science & Technology / Arts & Humanities / Commercial streams, with the correct mandatory and stream specific subjects';

    public function handle(TenantManager $tenants): int
    {
        $school = School::where('slug', $this->argument('school'))->firstOrFail();
        $tenants->use($school);

        $catalog = config('skulos.default_subjects.ss');
        $streamSubjects = config('skulos.sss_stream_subjects', []);
        $streamNames = array_keys($streamSubjects);

        // A strict "starts with sss/ss" prefix match, not the substring
        // keyword match config('skulos.default_subjects.ss')['match'] uses
        // for suggesting a catalog from a SECTION name: those same
        // keywords ('ss1', 'ss 1', ...) are also substrings of "JSS 1",
        // "JSS 2", "JSS 3", so reusing them against CLASS names wrongly
        // matched every JSS class too (found testing this command before
        // ever pointing it at a real school).
        $classes = SchoolClass::all()->filter(function (SchoolClass $class) {
            $name = strtolower(trim($class->name));

            return (bool) preg_match('/^s{2,3}\s?\d/', $name);
        });

        if ($classes->isEmpty()) {
            $this->info('No Senior Secondary classes found (matched against the "ss" default subject catalog).');
            $tenants->forget();

            return self::SUCCESS;
        }

        // Every subject named anywhere in the catalog or the stream map,
        // created once up front (firstOrCreate is safe to call inside the
        // loop too, but this keeps sequence numbers stable across
        // multiple classes sharing the same subject).
        $subjectsByName = [];
        foreach ($catalog['subjects'] as $index => $entry) {
            $subjectsByName[$entry['name']] = Subject::firstOrCreate(
                ['name' => $entry['name']],
                ['sequence' => $catalog['sequence_base'] + $index + 1]
            );
        }

        $streamArms = collect($streamNames)->mapWithKeys(fn ($name) => [$name => Arm::firstOrCreate(['name' => $name])]);

        foreach ($classes as $class) {
            $this->setupClass($class, $catalog, $subjectsByName, $streamSubjects, $streamArms);
        }

        $tenants->forget();
        $this->info('Done: '.$classes->count().' class(es) split into streams.');

        return self::SUCCESS;
    }

    /**
     * @param array<string, Subject> $subjectsByName
     * @param array<string, list<string>> $streamSubjects
     * @param \Illuminate\Support\Collection<string, Arm> $streamArms
     */
    protected function setupClass(SchoolClass $class, array $catalog, array $subjectsByName, array $streamSubjects, \Illuminate\Support\Collection $streamArms): void
    {
        $this->line("Setting up {$class->name}...");

        // Step 1: the class's own shared list, trimmed to mandatory +
        // non-stream-specific optional subjects only. sync() (not
        // syncWithoutDetaching) so re-running this command also detaches a
        // subject a stream has since claimed as its own, never left
        // stale/duplicated at both levels.
        $classLevelNames = $this->classLevelSubjectNames(collect($catalog['subjects'])->pluck('name'));
        $classLevelSync = collect($catalog['subjects'])
            ->filter(fn ($entry) => in_array($entry['name'], $classLevelNames, true))
            ->mapWithKeys(fn ($entry) => [
                $subjectsByName[$entry['name']]->id => ['is_mandatory' => $entry['mandatory'], 'is_examinable' => $entry['examinable']],
            ]);
        $class->subjects()->sync($classLevelSync);

        // Step 2: 3 real streams. The class's own existing single,
        // unstreamed arm is reused for the first stream rather than left
        // orphaned (the same pattern AcademicsController::storeArm()
        // already uses for a school adding its first stream by hand).
        $existingArms = ClassArm::where('school_class_id', $class->id)->get();
        $streamNames = array_keys($streamSubjects);
        $armByStream = [];

        // Every stream starts with the same class teacher the class had
        // before the split, not left blank (found live on greenfield,
        // 2026-07-24, real user report: "why is the report showing NA
        // for class teacher name and number since all class has class
        // teachers"), a real gap since only the reused/first stream
        // arm kept whichever teacher it already had, the other 2 newly
        // created arms started genuinely blank. A starting default, not
        // a final answer, the same "reuse what already exists, admin
        // reassigns" reasoning Step 6 below already uses for subject
        // teachers.
        $originalClassTeacherId = $existingArms->firstWhere('class_teacher_staff_id', '!=', null)?->class_teacher_staff_id;

        $unstreamed = $existingArms->firstWhere('arm_id', null);
        foreach ($streamNames as $i => $streamName) {
            $already = ClassArm::where('school_class_id', $class->id)->where('arm_id', $streamArms[$streamName]->id)->first();
            if ($already) {
                $armByStream[$streamName] = $already;
                if (! $already->class_teacher_staff_id && $originalClassTeacherId) {
                    $already->update(['class_teacher_staff_id' => $originalClassTeacherId]);
                }
            } elseif ($i === 0 && $unstreamed) {
                $unstreamed->update(['arm_id' => $streamArms[$streamName]->id]);
                $armByStream[$streamName] = $unstreamed;
            } else {
                $armByStream[$streamName] = ClassArm::create([
                    'school_class_id' => $class->id,
                    'arm_id' => $streamArms[$streamName]->id,
                    'class_teacher_staff_id' => $originalClassTeacherId,
                ]);
            }
        }

        // Step 3: each stream's own subject list at the arm level.
        foreach ($streamNames as $streamName) {
            $arm = $armByStream[$streamName];
            $subjectIds = collect($streamSubjects[$streamName])->map(fn ($name) => $subjectsByName[$name]->id ?? Subject::firstOrCreate(['name' => $name])->id);
            $arm->subjects()->sync($subjectIds->mapWithKeys(fn ($id) => [$id => ['is_mandatory' => true, 'is_examinable' => true]]));
        }

        // Step 4: split students evenly across the 3 streams, round robin
        // by admission number so re-running this command is deterministic.
        $studentArmIds = collect($armByStream)->map(fn (ClassArm $a) => $a->id)->values();
        $students = Student::whereIn('current_class_arm_id', $existingArms->pluck('id'))->orderBy('admission_number')->get();
        $originalArmIdByStudent = $students->pluck('current_class_arm_id', 'id');

        foreach ($students as $i => $student) {
            $newArmId = $studentArmIds[$i % $studentArmIds->count()];
            if ($student->current_class_arm_id !== $newArmId) {
                $student->update(['current_class_arm_id' => $newArmId]);
            }
        }

        // Step 5: re-point and clean up scores (and any subject remarks
        // already suggested against them) for every moved student. Also
        // the correct cleanup path on a RE-RUN after a stream's own
        // subject list is edited (e.g. trimmed to fit a subject count
        // cap): a student who never actually moved arms still has
        // $oldArmId === $newArmId here, so this still deletes a score/
        // remark for a subject the stream no longer claims, exactly the
        // same as a genuine cross-stream move.
        //
        // A stream's offeredSubjects() is a MENU (e.g. Arts & Humanities
        // offers both Christian Religious Studies and Islamic Studies, and
        // all 3 of Yoruba/Hausa/Igbo), not what any one student actually
        // takes — no student may keep a score in more than one member of a
        // config('skulos.mutually_exclusive_subjects') group (user
        // directive 2026-07-24), so ElectiveGroups::resolve() collapses
        // each group down to the single subject the student already has a
        // score for, or a deterministic pick if they have none yet.
        $offeredByArmId = collect($armByStream)->mapWithKeys(fn (ClassArm $a) => [$a->id => $a->offeredSubjects()]);
        foreach ($students as $student) {
            $oldArmId = $originalArmIdByStudent[$student->id];
            $newArmId = $student->current_class_arm_id;
            $existingScoredIds = Score::where('student_id', $student->id)->where('class_arm_id', $oldArmId)->pluck('subject_id');
            $keepSubjectIds = ElectiveGroups::resolve($offeredByArmId[$newArmId], $student->id, $existingScoredIds);

            Score::where('student_id', $student->id)->where('class_arm_id', $oldArmId)
                ->whereNotIn('subject_id', $keepSubjectIds)->delete();
            Score::where('student_id', $student->id)->where('class_arm_id', $oldArmId)
                ->update(['class_arm_id' => $newArmId]);

            SubjectRemark::where('student_id', $student->id)->where('class_arm_id', $oldArmId)
                ->whereNotIn('subject_id', $keepSubjectIds)->delete();
            SubjectRemark::where('student_id', $student->id)->where('class_arm_id', $oldArmId)
                ->update(['class_arm_id' => $newArmId]);
        }

        // Step 6: a real TeachingAssignment per stream per subject, reusing
        // whichever staff member already taught that subject on the
        // class's original arm(s), so score entry keeps working for a
        // scoped subject/class teacher, not just a blanket access admin.
        // Uses the full MENU, not any one student's collapsed pick: a
        // stream still needs an Islamic Studies teacher assigned even
        // though most of its students end up scored under Christian
        // Religious Studies instead.
        $originalAssignments = TeachingAssignment::whereIn('class_arm_id', $existingArms->pluck('id'))->get()->keyBy('subject_id');
        foreach ($streamNames as $streamName) {
            $arm = $armByStream[$streamName];
            foreach ($offeredByArmId[$arm->id]->pluck('id') as $subjectId) {
                $staffId = $originalAssignments[$subjectId]->staff_id ?? null;
                if ($staffId) {
                    TeachingAssignment::firstOrCreate(['class_arm_id' => $arm->id, 'subject_id' => $subjectId, 'staff_id' => $staffId]);
                }
            }
        }

        $this->line('  streams: '.collect($armByStream)->map(fn (ClassArm $a) => $a->label())->implode(', '));
    }

    /**
     * Subject names that belong at the class (shared) level: an explicit
     * allowlist (config('skulos.sss_shared_subjects')), not "whatever the
     * catalog has left once streams take theirs". A leftover computation
     * silently PROMOTES any subject dropped from a stream's own list (e.g.
     * trimming Arts & Humanities to fit the 11-subject-per-student cap) to
     * shared-by-every-stream instead of dropping it, which is how Music,
     * French and Arabic wrongly ended up mandatory for Science/Commercial
     * students too after that trim (found via a failing test 2026-07-23).
     */
    protected function classLevelSubjectNames(\Illuminate\Support\Collection $catalogNames): array
    {
        $shared = collect(config('skulos.sss_shared_subjects', []));

        return $catalogNames->filter(fn ($name) => $shared->contains($name))->values()->all();
    }
}
