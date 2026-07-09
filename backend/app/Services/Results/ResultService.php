<?php

namespace App\Services\Results;

use App\Models\Tenant\GradingScale;
use App\Models\Tenant\Score;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;

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
