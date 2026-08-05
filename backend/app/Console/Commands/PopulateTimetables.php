<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\ExamPaper;
use App\Models\Tenant\ExamTimetableEntry;
use App\Models\Tenant\Section;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\TimetableEntry;
use App\Models\Tenant\TimetablePeriod;
use App\Services\Tenancy\TenantManager;
use App\Services\Timetable\ExamTimetableService;
use App\Services\Timetable\TimetableService;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

/**
 * Populates real weekly and examination timetables for a school (user
 * request 2026-07-21: "THEN POPULATE THE TIMETABLES FOR CLASSES AND EXAM
 * FOR ALL CLASSES IN Greenfield Academy. MAKE USE OF THE SUBJECTS THERE
 * AND THE STAFF FOR EACH TEACHERS"), rather than a one-off script, so any
 * school can be populated/repopulated the same way, and so this is
 * reviewable and re-runnable like every other seeding command in this
 * codebase (schools:seed-demo-data).
 *
 * The requested per-section start time/period duration is NOT modeled:
 * TimetablePeriod is one shared catalog for the whole school by design
 * (every class arm's grid reads the same period rows), splitting that
 * into a real per-section catalog is a materially bigger schema change
 * than "populate some data" and out of scope here. The one shared
 * catalog already in place (08:00 start, 40 minute periods) is reused
 * as-is, matching the Secondary tier's own requested numbers exactly.
 */
class PopulateTimetables extends Command
{
    protected $signature = 'timetable:populate {school} {--exam-start=} {--exam-days-per-section=4}';

    protected $description = 'Populate the weekly class timetable and examination timetable with real subjects/staff for a school';

    public function handle(TenantManager $tenants, TimetableService $timetable, ExamTimetableService $exams): int
    {
        $school = School::where('slug', $this->argument('school'))->first();
        if (! $school) {
            $this->error('No school with that slug.');

            return self::FAILURE;
        }

        $tenants->use($school);

        $this->populateWeeklyTimetable($timetable);
        $this->populateExaminationTimetable($exams);

        $tenants->forget();
        $this->info('Done.');

        return self::SUCCESS;
    }

    /**
     * Rebuilt conflict-aware (user directive 2026-08-05, live report:
     * "all timetables are showing free... no class should have a free
     * period except for SSS 2 and 3 who can have a max of free period of
     * 1 per day which should be tagged as library for reading... twice a
     * week... no clash since we have just one library"). Two real
     * constraints beyond the original naive cycling:
     *
     * 1. A teacher can only be in one class at a time — checked directly
     *    here (the same query TimetableService::staffBookedElsewhere()
     *    runs internally, but that method is protected and this needs
     *    to try SEVERAL candidate pairs per cell before falling back,
     *    not just skip-and-report the way a single manual save does).
     * 2. The school has exactly one library, so at most one class arm
     *    may hold a library period in any given (day, period) slot —
     *    reserved up front, school-wide, before any subject is placed,
     *    so it can never lose out to a later-processed arm.
     *
     * There is no dedicated "free period"/"library" column on
     * TimetableEntry — a real Subject named "Library" (created if
     * missing) is used as the subject for that slot instead, with no
     * staff assigned (a supervised reading period needs no subject
     * teacher the way a lesson does).
     */
    protected function populateWeeklyTimetable(TimetableService $timetable): void
    {
        $periods = TimetablePeriod::where('is_teaching_period', true)->orderBy('sequence')->get();
        if ($periods->isEmpty()) {
            $this->warn('No teaching periods set up, skipping the weekly timetable.');

            return;
        }
        $weekDays = array_keys($timetable->weekDays());
        $librarySubject = Subject::firstOrCreate(['name' => 'Library']);

        $arms = ClassArm::with('schoolClass.section')->get()
            ->sortBy(fn (ClassArm $arm) => $arm->orderKey())->values();

        // Library eligibility: SSS 2 and SSS 3 only, matched on the
        // class's own name rather than a hardcoded id (portable across
        // schools whose SSS classes are seeded in any order).
        $libraryEligible = $arms->filter(fn (ClassArm $arm) => in_array(
            trim((string) $arm->schoolClass?->name), ['SSS 2', 'SSS 3'], true
        ));

        // Reserve 2 library slots per eligible arm, one per day, before
        // touching any subject — a school-wide set of occupied (day,
        // period) pairs is all that is needed to guarantee no two arms
        // ever claim the same slot, since there is only one library.
        $occupiedLibrarySlots = [];
        $librarySlotsFor = [];
        foreach ($libraryEligible as $arm) {
            $assigned = [];
            foreach ($weekDays as $day) {
                if (count($assigned) >= 2) {
                    break;
                }
                foreach ($periods as $period) {
                    $key = $day.'|'.$period->id;
                    if (! isset($occupiedLibrarySlots[$key])) {
                        $assigned[] = ['day' => $day, 'period_id' => $period->id];
                        $occupiedLibrarySlots[$key] = true;
                        break;
                    }
                }
            }
            $librarySlotsFor[$arm->id] = $assigned;
        }

        $armCount = 0;
        $conflictCells = 0;
        foreach ($arms as $arm) {
            // Every (subject, staff) pair this class actually has, cycled
            // to fill the week: a real curriculum repeats subjects across
            // the week rather than teaching each one exactly once.
            $pairs = TeachingAssignment::where('class_arm_id', $arm->id)->with('subject')->get()
                ->filter(fn (TeachingAssignment $a) => $a->subject_id && $a->staff_id)
                ->values();
            if ($pairs->isEmpty()) {
                continue;
            }

            $reservedLibrary = collect($librarySlotsFor[$arm->id] ?? [])
                ->mapWithKeys(fn ($slot) => [$slot['day'].'|'.$slot['period_id'] => true]);

            $cells = [];
            $cursor = 0;
            foreach ($weekDays as $day) {
                foreach ($periods as $period) {
                    if ($reservedLibrary->has($day.'|'.$period->id)) {
                        $cells[$day][$period->id] = ['subject_id' => $librarySubject->id, 'staff_id' => null];

                        continue;
                    }

                    // Try each candidate pair, starting from the round
                    // robin position, until one is genuinely free at
                    // this slot across every OTHER already-populated
                    // class arm. Falls back to the plain round robin
                    // choice (and counts it) only if every pair this
                    // class has is booked elsewhere at this exact slot,
                    // which a real, sane timetable should essentially
                    // never hit.
                    $picked = null;
                    for ($attempt = 0; $attempt < $pairs->count(); $attempt++) {
                        $candidate = $pairs[($cursor + $attempt) % $pairs->count()];
                        $booked = TimetableEntry::where('staff_id', $candidate->staff_id)
                            ->where('day_of_week', $day)->where('timetable_period_id', $period->id)
                            ->where('class_arm_id', '!=', $arm->id)->exists();
                        if (! $booked) {
                            $picked = $candidate;
                            $cursor += $attempt + 1;

                            break;
                        }
                    }
                    if (! $picked) {
                        $picked = $pairs[$cursor % $pairs->count()];
                        $cursor++;
                        $conflictCells++;
                    }

                    $cells[$day][$period->id] = ['subject_id' => $picked->subject_id, 'staff_id' => $picked->staff_id];
                }
            }

            $timetable->saveClassArmWeek($arm, $cells);
            $armCount++;
        }

        $this->info("Weekly timetable populated for {$armCount} class(es), {$libraryEligible->count()} with library periods.");
        if ($conflictCells > 0) {
            $this->warn("{$conflictCells} slot(s) could not find a fully conflict-free teacher and used the fallback pairing.");
        }
    }

    protected function populateExaminationTimetable(ExamTimetableService $exams): void
    {
        // A reasonable default exam day if none exists yet: two 2-hour
        // papers with a short break, matching the shape of the school's
        // own reference sample.
        if ($exams->papers()->isEmpty()) {
            $exams->applyPapers('09:00', [
                ['name' => 'Paper 1', 'duration_minutes' => 120, 'is_break' => false],
                ['name' => 'Break', 'duration_minutes' => 20, 'is_break' => true],
                ['name' => 'Paper 2', 'duration_minutes' => 120, 'is_break' => false],
            ]);
        }
        $teachingPapers = $exams->papers()->where('is_break', false)->values();
        if ($teachingPapers->isEmpty()) {
            $this->warn('No non-break exam papers set up, skipping the examination timetable.');

            return;
        }

        $startDate = $this->option('exam-start')
            ? Carbon::parse($this->option('exam-start'))
            : Carbon::now()->addWeek()->next(Carbon::MONDAY);
        $daysPerSection = max(1, (int) $this->option('exam-days-per-section'));

        $cursor = $startDate->copy();
        $count = 0;
        foreach (Section::orderBy('sequence')->get() as $section) {
            $subjectIds = Subject::whereHas('classes.section', fn ($q) => $q->whereKey($section->id))->pluck('id');
            if ($subjectIds->isEmpty()) {
                continue;
            }
            $subjectQueue = $subjectIds->values();
            $slotsNeeded = $daysPerSection * $teachingPapers->count();
            $rows = [];
            for ($i = 0; $i < $slotsNeeded && $i < $subjectQueue->count(); $i++) {
                // A single "if" only advances one day, which lands
                // squarely on a Sunday whenever the cursor was sitting on
                // a Saturday (real bug found live 2026-07-27, populating
                // Third Term 2025/2026's exam dates: "Sun, 12 Jul 2026"
                // showed up as a real exam date). A weekend is two days,
                // so skipping it needs a loop, not one hop.
                while ($cursor->isWeekend()) {
                    $cursor->addDay();
                }
                $paper = $teachingPapers[$i % $teachingPapers->count()];
                $rows[$cursor->toDateString()][$paper->id] = $subjectQueue[$i];
                if (($i + 1) % $teachingPapers->count() === 0) {
                    $cursor->addDay();
                }
            }

            $exams->saveEntries('section', $section->id, $rows);
            $count += count($rows);
            $cursor->addDay(); // a gap day between sections' exam blocks
        }

        $this->info("Examination timetable populated with {$count} date(s) worth of entries across every section.");
    }
}
