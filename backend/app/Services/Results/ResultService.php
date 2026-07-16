<?php

namespace App\Services\Results;

use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\Score;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use Illuminate\Support\Facades\DB;

/**
 * Computes results from raw component scores using the school's configured
 * grading scale. Nothing about the number of components, the weights, or the
 * grade bands is hardcoded here: it all comes from the tenant data a school
 * set up (assessment_components + grading_scales).
 */
class ResultService
{
    /**
     * Total + grade for one student in one subject and term.
     *
     * @return array{total: float, grade: ?string, remark: ?string, components: array<string, float>}
     */
    public function subjectResult(int $studentId, int $subjectId, int $termId, ?GradingScale $scale = null): array
    {
        $scale ??= GradingScale::default();

        $scores = Score::where('student_id', $studentId)
            ->where('subject_id', $subjectId)
            ->where('term_id', $termId)
            ->with('component')
            ->get();

        $total = (float) $scores->sum('score');
        $components = $scores->mapWithKeys(
            fn (Score $s) => [$s->component?->name ?? (string) $s->assessment_component_id => (float) $s->score]
        )->toArray();

        $band = $scale?->bandFor($total);

        return [
            'total' => $total,
            'grade' => $band?->grade,
            'remark' => $band?->remark,
            'components' => $components,
        ];
    }

    /**
     * The average subject percentage across every student and subject with
     * a recorded score in $termId, for dashboard figures (Term Average,
     * Result performance). Bug found 2026-07-15 (user report: "HOW COME
     * THE Term Average IS SUPER POOR... I WAS EXPECTING HIGHER THAN 80%"):
     * the dashboard previously did `Score::avg('score')` directly, a raw
     * average across individual ASSESSMENT COMPONENT rows (a Score is "one
     * component in one subject", per the model's own docblock, e.g. a CA
     * out of 20 and an Exam out of 60 are two separate rows), not subject
     * totals. Averaging a batch of ~15/20 CA scores together with ~45/60
     * exam scores as if they were on the same scale produces a number with
     * no real meaning, and reads far lower than the actual, correct
     * percentage a student earned (CA 15 + Exam 45 = 75/100, not ~30).
     * This sums components per (student, subject) first, matching exactly
     * what subjectResult() above already does per student, then averages
     * those normalized-to-100 totals, the mathematically correct number.
     */
    public function termAveragePercentage(int $termId): ?float
    {
        $totalPossible = AssessmentComponent::totalPossible();
        if ($totalPossible <= 0) {
            return null;
        }

        $subjectTotals = Score::where('term_id', $termId)
            ->select('student_id', 'subject_id', DB::raw('SUM(score) as subject_total'))
            ->groupBy('student_id', 'subject_id')
            ->get()
            ->pluck('subject_total');

        if ($subjectTotals->isEmpty()) {
            return null;
        }

        return round(((float) $subjectTotals->avg()) / $totalPossible * 100, 1);
    }

    /**
     * One student's own overall average percentage for a term, same
     * per-subject-sum-then-normalize approach as termAveragePercentage()
     * above (never a raw average across individual component rows, see
     * that method's docblock for why that reads meaninglessly low). Used
     * by the "students at risk" performance check, which previously used
     * a raw `Score::avg('score')` per student and would have had exactly
     * the same bug this method's sibling was fixed for.
     */
    public function studentAveragePercentage(int $studentId, int $termId): ?float
    {
        $totalPossible = AssessmentComponent::totalPossible();
        if ($totalPossible <= 0) {
            return null;
        }

        $subjectTotals = Score::where('student_id', $studentId)
            ->where('term_id', $termId)
            ->select('subject_id', DB::raw('SUM(score) as subject_total'))
            ->groupBy('subject_id')
            ->get()
            ->pluck('subject_total');

        if ($subjectTotals->isEmpty()) {
            return null;
        }

        return round(((float) $subjectTotals->avg()) / $totalPossible * 100, 1);
    }

    /**
     * A class broadsheet for one term: every student's total per subject,
     * their overall total and average, and their position in the class.
     *
     * @return array<int, array{student_id:int, name:string, subjects:array<int,float>, total:float, average:float, position:int}>
     */
    public function classBroadsheet(int $classArmId, int $termId): array
    {
        $subjectIds = Score::where('class_arm_id', $classArmId)
            ->where('term_id', $termId)
            ->distinct()
            ->pluck('subject_id');

        $studentIds = Score::where('class_arm_id', $classArmId)
            ->where('term_id', $termId)
            ->distinct()
            ->pluck('student_id');

        $students = Student::whereIn('id', $studentIds)->get()->keyBy('id');

        $rows = [];
        foreach ($studentIds as $studentId) {
            $subjects = [];
            foreach ($subjectIds as $subjectId) {
                $subjects[$subjectId] = (float) Score::where('student_id', $studentId)
                    ->where('subject_id', $subjectId)
                    ->where('term_id', $termId)
                    ->sum('score');
            }
            $total = array_sum($subjects);
            $count = count(array_filter($subjects, fn ($v) => $v > 0)) ?: count($subjects);

            $rows[] = [
                'student_id' => $studentId,
                'name' => $students[$studentId]?->fullName() ?? (string) $studentId,
                'subjects' => $subjects,
                'total' => $total,
                'average' => $count ? round($total / $count, 2) : 0.0,
            ];
        }

        // Rank by total desc; equal totals share a position (competition ranking).
        usort($rows, fn ($a, $b) => $b['total'] <=> $a['total']);
        $position = 0;
        $seen = 0;
        $lastTotal = null;
        foreach ($rows as &$row) {
            $seen++;
            if ($lastTotal === null || $row['total'] < $lastTotal) {
                $position = $seen;
                $lastTotal = $row['total'];
            }
            $row['position'] = $position;
        }

        return $rows;
    }

    /**
     * One subject's standing across a class arm for a term: every student's
     * total, the class average/highest/lowest, and each student's position
     * within that single subject (competition ranking, ties share a spot).
     *
     * @return array{average: float, highest: float, lowest: float, positions: array<int,int>, totals: array<int,float>}
     */
    public function subjectStats(int $classArmId, int $subjectId, int $termId): array
    {
        $studentIds = Score::where('class_arm_id', $classArmId)
            ->where('subject_id', $subjectId)
            ->where('term_id', $termId)
            ->distinct()
            ->pluck('student_id');

        $totals = [];
        foreach ($studentIds as $studentId) {
            $totals[$studentId] = (float) Score::where('student_id', $studentId)
                ->where('subject_id', $subjectId)
                ->where('term_id', $termId)
                ->sum('score');
        }

        if (empty($totals)) {
            return ['average' => 0.0, 'highest' => 0.0, 'lowest' => 0.0, 'positions' => [], 'totals' => []];
        }

        $ranked = $totals;
        arsort($ranked);
        $positions = [];
        $position = 0;
        $seen = 0;
        $lastTotal = null;
        foreach ($ranked as $studentId => $total) {
            $seen++;
            if ($lastTotal === null || $total < $lastTotal) {
                $position = $seen;
                $lastTotal = $total;
            }
            $positions[$studentId] = $position;
        }

        return [
            'average' => round(array_sum($totals) / count($totals), 2),
            'highest' => max($totals),
            'lowest' => min($totals),
            'positions' => $positions,
            'totals' => $totals,
        ];
    }

    /**
     * Convenience: subject name => result for a student's full term report.
     *
     * @return array<string, array{total: float, grade: ?string, remark: ?string, components: array<string,float>}>
     */
    public function studentReport(int $studentId, int $termId, ?GradingScale $scale = null): array
    {
        $scale ??= GradingScale::default();

        $subjectIds = Score::where('student_id', $studentId)
            ->where('term_id', $termId)
            ->distinct()
            ->pluck('subject_id');

        $subjects = Subject::whereIn('id', $subjectIds)->get()->keyBy('id');

        $report = [];
        foreach ($subjectIds as $subjectId) {
            $name = $subjects[$subjectId]?->name ?? (string) $subjectId;
            $report[$name] = $this->subjectResult($studentId, $subjectId, $termId, $scale);
        }

        return $report;
    }
}
