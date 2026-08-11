<?php

namespace App\Services\Academics;

use App\Models\Tenant\ClassArm;
use App\Models\Tenant\PromotionRun;
use App\Models\Tenant\PromotionRunItem;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
use App\Services\Results\ResultService;
use Illuminate\Support\Facades\DB;

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
     * Admin-triggered promotion (feedback list 8/7/2026, item 9), a
     * second, separate path alongside promote() above — run on demand
     * against a chosen source term, with an admin-chosen threshold, and
     * persisted as a real PromotionRun/PromotionRunItem report instead
     * of bare counts. $toTerm is a label only (does not call
     * makeCurrent() on anything, that stays endTerm()'s own job).
     *
     * Unlike promote(), this loops each class arm's actual ACTIVE
     * roster (not just classBroadsheet()'s rows, which only include
     * students with at least one score) so a scoreless student is still
     * recorded as "no results," not silently skipped. Also guards
     * against double-promoting an already-moved student on a re-run:
     * classBroadsheet() keys off Score.class_arm_id (fixed at
     * score-entry time), not the student's live current_class_arm_id,
     * so a second run against the same term would otherwise re-evaluate
     * the same roster.
     *
     * Every class arm's roster and broadsheet is snapshotted BEFORE any
     * student is moved — a real bug caught by this method's own test:
     * moving a student out of one class arm mid-loop otherwise makes
     * them show up again, live, in whichever class arm they just moved
     * INTO, if that arm is processed later in the same run, producing a
     * spurious duplicate "no results" entry for someone already
     * correctly promoted moments earlier in the same run.
     */
    public function runManual(Term $sourceTerm, float $threshold, ?Term $toTerm, int $runByUserId): PromotionRun
    {
        $nextClassId = $this->nextClassMap();

        $snapshot = ClassArm::with('schoolClass')->get()->map(function (ClassArm $classArm) use ($sourceTerm) {
            return [
                'classArm' => $classArm,
                'averages' => collect($this->results->classBroadsheet($classArm->id, $sourceTerm->id))->keyBy('student_id'),
                'roster' => Student::where('current_class_arm_id', $classArm->id)->where('status', Student::STATUS_ACTIVE)->get(),
            ];
        });

        return DB::connection('tenant')->transaction(function () use ($snapshot, $threshold, $toTerm, $sourceTerm, $runByUserId, $nextClassId) {
            $run = PromotionRun::create([
                'term_id' => $sourceTerm->id,
                'to_term_id' => $toTerm?->id,
                'threshold' => $threshold,
                'run_by_user_id' => $runByUserId,
            ]);

            $counts = ['promoted' => 0, 'repeated' => 0, 'graduated' => 0, 'skipped' => 0];

            foreach ($snapshot as $entry) {
                $classArm = $entry['classArm'];
                $averages = $entry['averages'];
                $roster = $entry['roster'];

                if ($roster->isEmpty()) {
                    continue;
                }

                $nextClass = $nextClassId[$classArm->school_class_id] ?? null;
                $nextClassArm = $nextClass
                    ? (ClassArm::where('school_class_id', $nextClass)->where('arm_id', $classArm->arm_id)->first()
                        ?? ClassArm::where('school_class_id', $nextClass)->orderBy('id')->first())
                    : null;

                foreach ($roster as $student) {
                    if ($student->current_class_arm_id !== $classArm->id) {
                        $this->recordItem($run, $student, $classArm->id, null, null, PromotionRunItem::OUTCOME_SKIPPED_ALREADY_MOVED, 'Already moved to a different class since this run started.');
                        $counts['skipped']++;

                        continue;
                    }

                    $row = $averages->get($student->id);
                    if (! $row) {
                        $this->recordItem($run, $student, $classArm->id, null, null, PromotionRunItem::OUTCOME_SKIPPED_NO_RESULTS, 'No results recorded for this term.');
                        $counts['skipped']++;

                        continue;
                    }

                    if ($row['average'] < $threshold) {
                        $this->recordItem($run, $student, $classArm->id, null, $row['average'], PromotionRunItem::OUTCOME_REPEATED, "Average {$row['average']}% below the {$threshold}% threshold.");
                        $counts['repeated']++;

                        continue;
                    }

                    if ($nextClassArm) {
                        $student->update(['current_class_arm_id' => $nextClassArm->id]);
                        $this->recordItem($run, $student, $classArm->id, $nextClassArm->id, $row['average'], PromotionRunItem::OUTCOME_PROMOTED, null);
                        $counts['promoted']++;
                    } else {
                        $student->update(['status' => Student::STATUS_GRADUATED]);
                        $this->recordItem($run, $student, $classArm->id, null, $row['average'], PromotionRunItem::OUTCOME_GRADUATED, null);
                        $counts['graduated']++;
                    }
                }
            }

            $run->update([
                'promoted_count' => $counts['promoted'],
                'repeated_count' => $counts['repeated'],
                'graduated_count' => $counts['graduated'],
                'skipped_count' => $counts['skipped'],
            ]);

            return $run->fresh('items');
        });
    }

    protected function recordItem(PromotionRun $run, Student $student, int $fromClassArmId, ?int $toClassArmId, ?float $average, string $outcome, ?string $reason): void
    {
        PromotionRunItem::create([
            'promotion_run_id' => $run->id,
            'student_id' => $student->id,
            'from_class_arm_id' => $fromClassArmId,
            'to_class_arm_id' => $toClassArmId,
            'average' => $average,
            'outcome' => $outcome,
            'reason' => $reason,
        ]);
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
