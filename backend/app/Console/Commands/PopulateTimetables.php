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

    protected function populateWeeklyTimetable(TimetableService $timetable): void
    {
        $periods = TimetablePeriod::where('is_teaching_period', true)->orderBy('sequence')->get();
        if ($periods->isEmpty()) {
            $this->warn('No teaching periods set up, skipping the weekly timetable.');

            return;
        }
        $weekDays = array_keys($timetable->weekDays());

        $armCount = 0;
        foreach (ClassArm::with('schoolClass.section')->get() as $arm) {
            // Every (subject, staff) pair this class actually has, cycled
            // to fill the week: a real curriculum repeats subjects across
            // the week rather than teaching each one exactly once.
            $pairs = TeachingAssignment::where('class_arm_id', $arm->id)->with('subject')->get()
                ->filter(fn (TeachingAssignment $a) => $a->subject_id && $a->staff_id)
                ->values();
            if ($pairs->isEmpty()) {
                continue;
            }

            $cells = [];
            $cursor = 0;
            foreach ($weekDays as $day) {
                foreach ($periods as $period) {
                    $pair = $pairs[$cursor % $pairs->count()];
                    $cursor++;
                    $cells[$day][$period->id] = ['subject_id' => $pair->subject_id, 'staff_id' => $pair->staff_id];
                }
            }

            $timetable->saveClassArmWeek($arm, $cells);
            $armCount++;
        }

        $this->info("Weekly timetable populated for {$armCount} class(es).");
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
                if ($cursor->isWeekend()) {
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
