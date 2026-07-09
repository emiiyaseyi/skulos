<?php

namespace App\Services\ReportCards;

use App\Models\Tenant\Attendance;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentTermRemark;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
use App\Services\Results\ResultService;

/**
 * Builds the data a report card view needs, whatever template/fields the
 * school has chosen. Nothing here assumes exactly 3 terms: it walks every
 * term up to and including the one being rendered, in sequence order, and
 * shows each earlier term's subject total as its own column plus a
 * cumulative average — the "third term brings in first and second term
 * scores" request generalised to any number of terms.
 */
class ReportCardService
{
    public function __construct(protected ResultService $results)
    {
    }

    /**
     * Real report card data for one student, one term, computed from
     * whatever scores/attendance/remarks exist in the tenant database.
     */
    public function forStudent(Student $student, Term $term, ?GradingScale $scale = null): array
    {
        $scale ??= GradingScale::default();
        $classArm = $student->currentClassArm;

        $priorTerms = Term::where('academic_session_id', $term->academic_session_id)
            ->where('sequence', '<=', $term->sequence)
            ->orderBy('sequence')
            ->get();

        $subjectIds = \App\Models\Tenant\Score::where('student_id', $student->id)
            ->where('term_id', $term->id)
            ->distinct()
            ->pluck('subject_id');
        $subjects = Subject::whereIn('id', $subjectIds)->get()->keyBy('id');

        $subjectRows = [];
        foreach ($subjectIds as $subjectId) {
            $current = $this->results->subjectResult($student->id, $subjectId, $term->id, $scale);

            $previousTerms = [];
            $termTotals = [];
            foreach ($priorTerms as $t) {
                $total = $t->id === $term->id
                    ? $current['total']
                    : $this->results->subjectResult($student->id, $subjectId, $t->id, $scale)['total'];
                $termTotals[] = $total;
                if ($t->id !== $term->id) {
                    $previousTerms[$t->name] = $total;
                }
            }
            $cumulativeAverage = count($termTotals) ? round(array_sum($termTotals) / count($termTotals), 2) : 0.0;

            $stats = $classArm
                ? $this->results->subjectStats($classArm->id, $subjectId, $term->id)
                : ['average' => 0.0, 'highest' => 0.0, 'lowest' => 0.0, 'positions' => []];

            $subjectRows[] = [
                'name' => $subjects[$subjectId]?->name ?? (string) $subjectId,
                'components' => $current['components'],
                'total' => $current['total'],
                'grade' => $current['grade'],
                'remark' => $current['remark'],
                'position' => $stats['positions'][$student->id] ?? null,
                'class_average' => $stats['average'],
                'highest' => $stats['highest'],
                'lowest' => $stats['lowest'],
                'previous_terms' => $previousTerms,
                'cumulative_average' => $cumulativeAverage,
            ];
        }

        $overallTotal = array_sum(array_column($subjectRows, 'total'));
        $overallAverage = count($subjectRows) ? round($overallTotal / count($subjectRows), 2) : 0.0;

        $overallPosition = null;
        $classSize = null;
        if ($classArm) {
            $broadsheet = $this->results->classBroadsheet($classArm->id, $term->id);
            $classSize = count($broadsheet);
            foreach ($broadsheet as $row) {
                if ($row['student_id'] === $student->id) {
                    $overallPosition = $row['position'];
                    break;
                }
            }
        }

        $attendance = ['present' => 0, 'total' => 0];
        if ($term->starts_on && $term->ends_on) {
            $base = Attendance::students()
                ->where('person_id', $student->id)
                ->whereBetween('date', [$term->starts_on->toDateString(), $term->ends_on->toDateString()]);
            $attendance['total'] = (clone $base)->count();
            $attendance['present'] = (clone $base)->where('status', 'present')->count();
        }

        $remark = StudentTermRemark::where('student_id', $student->id)->where('term_id', $term->id)->first();

        return [
            'identity' => [
                'name' => $student->fullName(),
                'admission_number' => $student->admission_number,
                'gender' => $student->gender,
                'date_of_birth' => $student->date_of_birth?->format('d M Y'),
                'age' => $student->date_of_birth?->age,
                'class' => $classArm?->label(),
                'house' => $student->house?->name,
                'class_teacher' => $classArm?->class_teacher_staff_id
                    ? Staff::find($classArm->class_teacher_staff_id)?->fullName()
                    : null,
            ],
            'term' => ['name' => $term->name, 'session' => $term->session?->name],
            'subjects' => $subjectRows,
            'attendance' => $attendance,
            'overall' => [
                'total' => $overallTotal,
                'average' => $overallAverage,
                'position' => $overallPosition,
                'class_size' => $classSize,
            ],
            'remarks' => [
                'class_teacher' => $remark?->class_teacher_remark,
                'principal' => $remark?->principal_remark,
                'next_term_begins_on' => $remark?->next_term_begins_on?->format('d M Y'),
            ],
        ];
    }

    /**
     * Static illustrative data, shaped identically to forStudent(), used
     * only so an admin can preview each template/field combination before
     * any real scores exist.
     */
    public function sample(): array
    {
        return [
            'identity' => [
                'name' => 'Ada Grace Obi', 'admission_number' => 'ADM/2025/014', 'gender' => 'Female',
                'date_of_birth' => '12 Mar 2013', 'age' => 13, 'class' => 'JSS 2 A', 'house' => 'Red House',
                'class_teacher' => 'Mrs. F. Adigun',
            ],
            'term' => ['name' => 'Third Term', 'session' => '2025/2026'],
            'subjects' => [
                ['name' => 'Mathematics', 'components' => ['First CA' => 18, 'Second CA' => 17, 'Exam' => 50], 'total' => 85, 'grade' => 'A', 'remark' => 'Distinction', 'position' => 1, 'class_average' => 68.4, 'highest' => 92, 'lowest' => 31, 'previous_terms' => ['First Term' => 78.0, 'Second Term' => 81.0], 'cumulative_average' => 81.33],
                ['name' => 'English Studies', 'components' => ['First CA' => 15, 'Second CA' => 16, 'Exam' => 48], 'total' => 79, 'grade' => 'B', 'remark' => 'Upper Credit / Very Good', 'position' => 3, 'class_average' => 65.1, 'highest' => 88, 'lowest' => 28, 'previous_terms' => ['First Term' => 74.0, 'Second Term' => 76.0], 'cumulative_average' => 76.33],
                ['name' => 'Basic Science', 'components' => ['First CA' => 17, 'Second CA' => 18, 'Exam' => 45], 'total' => 80, 'grade' => 'A', 'remark' => 'Distinction', 'position' => 2, 'class_average' => 60.7, 'highest' => 90, 'lowest' => 22, 'previous_terms' => ['First Term' => 70.0, 'Second Term' => 75.0], 'cumulative_average' => 75.0],
                ['name' => 'Civic Education', 'components' => ['First CA' => 14, 'Second CA' => 15, 'Exam' => 40], 'total' => 69, 'grade' => 'B', 'remark' => 'Upper Credit / Very Good', 'position' => 5, 'class_average' => 62.0, 'highest' => 85, 'lowest' => 30, 'previous_terms' => ['First Term' => 65.0, 'Second Term' => 67.0], 'cumulative_average' => 67.0],
            ],
            'attendance' => ['present' => 58, 'total' => 62],
            'overall' => ['total' => 313, 'average' => 78.25, 'position' => 2, 'class_size' => 28],
            'remarks' => [
                'class_teacher' => 'A hardworking and consistent student this term. Keep it up.',
                'principal' => 'Impressive result. Well done.',
                'next_term_begins_on' => '08 Sep 2026',
            ],
        ];
    }
}
