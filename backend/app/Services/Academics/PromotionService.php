<?php

namespace App\Services\Academics;

use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
use App\Services\Results\ResultService;

/**
 * Automatic student promotion at the end of a school's final term in a
 * session (user request 2026-07-11). A student's overall average for the
 * ending term, per class arm via ResultService::classBroadsheet(), is
 * compared against the school's own configurable promotion threshold
 * (tenant Setting "promotion_threshold", default 40, editable any time
 * under Academics settings, per "school admins will need to set this as
 * part of their one time setup and can always come edit it").
 *
 * The "next class" a promoted student moves into is derived, not
 * hardcoded: every SchoolClass across every Section is ordered by
 * (section.sequence, class.sequence), and a student moves into whichever
 * class comes right after their current one in that combined order. A
 * student already in the very last class of that order graduates instead
 * of being promoted into nothing. Where the next class has multiple
 * streams, a student's own stream name is preserved when one of the same
 * name exists there (e.g. "Primary 6 A" to "JSS 1 A"), falling back to
 * that class's first stream otherwise.
 */
class PromotionService
{
    public function __construct(protected ResultService $results)
    {
    }

    public function defaultThreshold(): float
    {
        return (float) Setting::grab('promotion_threshold', 40);
    }

    /**
     * @return array{promoted: int, repeated: int, graduated: int}
     */
    public function promote(Term $endingTerm): array
    {
        $threshold = $this->defaultThreshold();
        $nextClassId = $this->nextClassMap();

        $promoted = 0;
        $repeated = 0;
        $graduated = 0;

        foreach (ClassArm::with('schoolClass')->get() as $classArm) {
            $sheet = $this->results->classBroadsheet($classArm->id, $endingTerm->id);
            if (empty($sheet)) {
                continue;
            }

            $nextClass = $nextClassId[$classArm->school_class_id] ?? null;
            $nextClassArm = $nextClass
                ? (ClassArm::where('school_class_id', $nextClass)->where('arm_id', $classArm->arm_id)->first()
                    ?? ClassArm::where('school_class_id', $nextClass)->orderBy('id')->first())
                : null;

            foreach ($sheet as $row) {
                $student = Student::find($row['student_id']);
                if (! $student || $student->status !== Student::STATUS_ACTIVE) {
                    continue;
                }

                if ($row['average'] < $threshold) {
                    $repeated++;

                    continue;
                }

                if ($nextClassArm) {
                    $student->update(['current_class_arm_id' => $nextClassArm->id]);
                    $promoted++;
                } else {
                    $student->update(['status' => Student::STATUS_GRADUATED]);
                    $graduated++;
                }
            }
        }

        return ['promoted' => $promoted, 'repeated' => $repeated, 'graduated' => $graduated];
    }

    /**
     * @return array<int, int|null> SchoolClass id => next SchoolClass id (or null if last)
     */
    protected function nextClassMap(): array
    {
        $classes = SchoolClass::with('section')->get()
            ->sortBy(fn (SchoolClass $c) => ($c->section->sequence ?? 0) * 10000 + $c->sequence)
            ->values();

        $map = [];
        foreach ($classes as $index => $class) {
            $map[$class->id] = $classes->get($index + 1)?->id;
        }

        return $map;
    }
}
