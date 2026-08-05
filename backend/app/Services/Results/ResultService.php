<?php

namespace App\Services\Results;

use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\Score;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
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
        $totalPossible = AssessmentComponent::totalPossible($termId);
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
        $totalPossible = AssessmentComponent::totalPossible($termId);
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
     * $enteredFrom/$enteredTo optionally narrow to scores whose own
     * `created_at` (when the score row was actually entered, the only real
     * per-score timestamp this data model has, there is no separate
     * "assessed on" date) falls in that range, on top of the term scope
     * (Subject Insights user request 2026-07-22: "the necessary date,
     * filter and sort options and calendar date option and term option").
     *
     * @return array{average: float, highest: float, lowest: float, positions: array<int,int>, totals: array<int,float>}
     */
    public function subjectStats(int $classArmId, int $subjectId, int $termId, ?\Carbon\Carbon $enteredFrom = null, ?\Carbon\Carbon $enteredTo = null): array
    {
        $scoped = fn ($q) => $q
            ->when($enteredFrom, fn ($qq) => $qq->where('created_at', '>=', $enteredFrom->startOfDay()))
            ->when($enteredTo, fn ($qq) => $qq->where('created_at', '<=', $enteredTo->endOfDay()));

        $studentIds = $scoped(Score::where('class_arm_id', $classArmId)
            ->where('subject_id', $subjectId)
            ->where('term_id', $termId))
            ->distinct()
            ->pluck('student_id');

        $totals = [];
        foreach ($studentIds as $studentId) {
            $totals[$studentId] = (float) $scoped(Score::where('student_id', $studentId)
                ->where('subject_id', $subjectId)
                ->where('term_id', $termId))
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

    /**
     * Same normalized per-subject-sum-then-average approach as
     * termAveragePercentage(), scoped to a set of class arms (Student
     * Performance Dashboard, 2026-07-16: a scoped teacher role's own
     * average across whichever arm(s) TeacherScope narrows them to,
     * one or several).
     */
    public function averagePercentageForClassArms(array $classArmIds, int $termId): ?float
    {
        $totalPossible = AssessmentComponent::totalPossible($termId);
        if ($totalPossible <= 0 || $classArmIds === []) {
            return null;
        }

        $subjectTotals = Score::whereIn('class_arm_id', $classArmIds)
            ->where('term_id', $termId)
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
     * Same as averagePercentageForClassArms(), scoped to one class arm
     * (the "by class" comparison list, mirroring the Attendance
     * Dashboard's own per-class rate list).
     */
    public function classAveragePercentage(int $classArmId, int $termId): ?float
    {
        return $this->averagePercentageForClassArms([$classArmId], $termId);
    }

    /**
     * The last N terms with any real scores recorded, in real
     * chronological order (academic session then term sequence, not
     * creation order), each with the real school wide average across
     * every score in that term. A term with zero scores yet is skipped
     * rather than shown as a misleading 0%. Moved here from
     * RoleDashboard::resultTrend() (2026-07-16, Student Performance
     * Dashboard) so the same real trend computation is not duplicated
     * between the home dashboard's own widget and this dedicated screen.
     *
     * @return list<array{label: string, average: float}>
     */
    public function resultTrend(int $limit = 6): array
    {
        $terms = Term::with('session')->get()
            ->sortBy(fn (Term $t) => sprintf('%05d-%05d', $t->session?->id ?? 0, $t->sequence ?? 0))
            ->values();

        $trend = [];
        foreach ($terms as $term) {
            $average = $this->termAveragePercentage($term->id);
            if ($average === null) {
                continue;
            }
            $trend[] = ['label' => $term->name, 'average' => $average];
        }

        return array_slice($trend, -$limit);
    }

    /**
     * School wide, per subject average/highest/lowest percentage for a
     * term (Academic Insights, 2026-07-18), normalized the same
     * sum-components-then-percentage way as averagePercentageForClassArms()
     * but grouped by subject across every class arm, not scoped to any
     * one class. A whole-school view on purpose, distinct from the
     * Performance Dashboard's per-class comparison.
     *
     * @return \Illuminate\Support\Collection<int, array{subject: string, average: float, highest: float, lowest: float, students_assessed: int}>
     */
    public function subjectAveragesForTerm(int $termId, ?array $classArmIds = null): \Illuminate\Support\Collection
    {
        $totalPossible = AssessmentComponent::totalPossible($termId);
        if ($totalPossible <= 0) {
            return collect();
        }

        $bySubject = Score::where('term_id', $termId)
            ->when($classArmIds !== null, fn ($q) => $q->whereIn('class_arm_id', $classArmIds))
            ->selectRaw('subject_id, student_id, SUM(score) as subject_total')
            ->groupBy('subject_id', 'student_id')
            ->get()
            ->groupBy('subject_id');

        if ($bySubject->isEmpty()) {
            return collect();
        }

        $subjects = Subject::whereIn('id', $bySubject->keys())->get()->keyBy('id');

        return $bySubject->map(function ($rows, $subjectId) use ($totalPossible, $subjects) {
            $totals = $rows->pluck('subject_total')->map(fn ($t) => (float) $t);

            return [
                'subject' => $subjects[$subjectId]?->name ?? (string) $subjectId,
                'average' => round(((float) $totals->avg()) / $totalPossible * 100, 1),
                'highest' => round($totals->max() / $totalPossible * 100, 1),
                'lowest' => round($totals->min() / $totalPossible * 100, 1),
                'students_assessed' => $totals->count(),
            ];
        })->sortByDesc('average')->values();
    }

    /**
     * School wide grade distribution for a term (Academic Insights,
     * 2026-07-18): how many student-subject entries landed in each grade
     * band, across the whole school, grade => count, highest count first.
     */
    public function gradeDistributionForTerm(int $termId, ?GradingScale $scale = null, ?array $classArmIds = null): array
    {
        $scale ??= GradingScale::default();
        if (! $scale) {
            return [];
        }

        $totals = Score::where('term_id', $termId)
            ->when($classArmIds !== null, fn ($q) => $q->whereIn('class_arm_id', $classArmIds))
            ->selectRaw('subject_id, student_id, SUM(score) as subject_total')
            ->groupBy('subject_id', 'student_id')
            ->pluck('subject_total');

        $counts = [];
        foreach ($totals as $total) {
            $grade = $scale->bandFor((float) $total)?->grade ?? 'Ungraded';
            $counts[$grade] = ($counts[$grade] ?? 0) + 1;
        }

        arsort($counts);

        return $counts;
    }

    /**
     * Every student in the given collection whose own term average falls
     * below the threshold, worst first. $limit null returns the full
     * list (Student Performance Dashboard's own screen); RoleDashboard's
     * dashboard widget still caps this at a handful via $limit.
     *
     * @param \Illuminate\Support\Collection<int, Student> $students
     * @return list<array{name: string, class: ?string, average: float}>
     */
    public function studentsBelowAverageThreshold($students, int $termId, float $thresholdPct, ?int $limit = null): array
    {
        $totalPossible = AssessmentComponent::totalPossible($termId);
        if ($totalPossible <= 0) {
            return [];
        }

        $subjectTotalsByStudent = Score::where('term_id', $termId)
            ->selectRaw('student_id, subject_id, SUM(score) as subject_total')
            ->groupBy('student_id', 'subject_id')
            ->get()
            ->groupBy('student_id');

        $atRisk = [];
        foreach ($students as $student) {
            $totals = $subjectTotalsByStudent->get($student->id);
            if (! $totals) {
                continue;
            }
            $avgPct = round(((float) $totals->avg('subject_total')) / $totalPossible * 100, 1);
            if ($avgPct < $thresholdPct) {
                $atRisk[] = [
                    'name' => $student->fullName(),
                    'class' => $student->currentClassArm?->schoolClass?->name,
                    'average' => $avgPct,
                ];
            }
        }

        usort($atRisk, fn ($a, $b) => $a['average'] <=> $b['average']);

        return $limit !== null ? array_slice($atRisk, 0, $limit) : $atRisk;
    }

    /**
     * Every given student's own term average percentage, in one bulk
     * grouped query (Report Builder's "Performance" column, user request
     * 2026-07-22: "report builder should have much more items to combine
     * into a report... performance"), not one studentAveragePercentage()
     * call per row, which would be an N+1 query for a school-wide report.
     * A student with no scores yet for the term is simply absent from the
     * returned map rather than a null entry.
     *
     * @param array<int, int> $studentIds
     * @return array<int, float> student id => average percentage
     */
    public function averagesByStudent(array $studentIds, int $termId): array
    {
        $totalPossible = AssessmentComponent::totalPossible($termId);
        if ($totalPossible <= 0 || empty($studentIds)) {
            return [];
        }

        return Score::where('term_id', $termId)
            ->whereIn('student_id', $studentIds)
            ->selectRaw('student_id, subject_id, SUM(score) as subject_total')
            ->groupBy('student_id', 'subject_id')
            ->get()
            ->groupBy('student_id')
            ->map(fn ($subjectTotals) => round(((float) $subjectTotals->avg('subject_total')) / $totalPossible * 100, 1))
            ->all();
    }
}
