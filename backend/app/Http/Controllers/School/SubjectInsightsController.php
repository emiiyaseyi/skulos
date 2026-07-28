<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Staff;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use App\Services\Results\ResultService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Subject Insights (2026-07-22 user request: "report and analytics should
 * have subject insights for class teacher and subject teachers with the
 * necessary date, filter and sort options and calendar date option and
 * term option"). Distinct from Academic Insights
 * (AcademicInsightsController), which is a whole school, leadership only
 * ranking: this is a teacher's own view of only the (class, subject) pairs
 * their own TeachingAssignment rows say they actually teach, exactly the
 * same scoping ExaminationController::allowedPairs() uses for score entry,
 * so "what a teacher can see here" always matches "what they can enter
 * scores for". A user with no Staff record (e.g. a super admin previewing)
 * or no TeachingAssignment rows simply sees an empty state, this screen is
 * never a school wide view, unlike Academic Insights.
 */
class SubjectInsightsController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    public function index(Request $request, ResultService $results): \Illuminate\View\View
    {
        $school = $this->school($request);
        abort_unless(app(Rbac::class)->check(Auth::user(), $school, 'examinations.view'), 403, 'You do not have permission for this.');

        $terms = Term::with('session')->orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', (int) $request->query('term_id')) ?? $terms->firstWhere('is_current', true) ?? $terms->first();

        $staff = Staff::where('user_id', Auth::id())->first();
        $pairs = $staff
            ? TeachingAssignment::where('staff_id', $staff->id)
                ->with(['subject', 'classArm.schoolClass.section', 'classArm.arm'])
                ->get()
                ->map(fn (TeachingAssignment $a) => [
                    'class_arm_id' => (int) $a->class_arm_id,
                    'subject_id' => (int) $a->subject_id,
                    'class_label' => $a->classArm->label(),
                    'subject_name' => $a->subject->name,
                    'order' => $a->classArm->orderKey().'-'.$a->subject->name,
                ])
                ->unique(fn ($p) => $p['class_arm_id'].':'.$p['subject_id'])
                ->sortBy('order')
                ->values()
            : collect();

        // Filter down to one class and/or one subject (user request:
        // "the necessary date, filter and sort options"). A filter value
        // that isn't actually one of this teacher's own pairs is ignored
        // rather than erroring, since these are convenience narrowings on
        // top of an already-scoped list, not an access control boundary.
        $classArmId = $request->integer('class_arm_id') ?: null;
        $subjectId = $request->integer('subject_id') ?: null;

        $classOptions = $pairs->unique('class_arm_id')->pluck('class_label', 'class_arm_id');
        $subjectOptions = $pairs->unique('subject_id')->pluck('subject_name', 'subject_id');

        $filteredPairs = $pairs
            ->when($classArmId, fn ($c) => $c->where('class_arm_id', $classArmId))
            ->when($subjectId, fn ($c) => $c->where('subject_id', $subjectId));

        // Calendar date range (user request 2026-07-22: "...calendar date
        // option and term option"), narrowing to scores actually entered in
        // that window, on top of the term filter. Invalid/reversed input is
        // silently ignored rather than erroring, same convenience-narrowing
        // treatment as the class/subject filters above.
        $enteredFrom = $this->parseDate($request->query('from_date'));
        $enteredTo = $this->parseDate($request->query('to_date'));
        if ($enteredFrom && $enteredTo && $enteredFrom->gt($enteredTo)) {
            [$enteredFrom, $enteredTo] = [$enteredTo, $enteredFrom];
        }

        $rows = collect();
        if ($term) {
            $rows = $filteredPairs->map(function ($pair) use ($results, $term, $enteredFrom, $enteredTo) {
                $stats = $results->subjectStats($pair['class_arm_id'], $pair['subject_id'], $term->id, $enteredFrom, $enteredTo);

                return [
                    'class_label' => $pair['class_label'],
                    'subject_name' => $pair['subject_name'],
                    'students' => count($stats['totals']),
                    'average' => $stats['average'],
                    'highest' => $stats['highest'],
                    'lowest' => $stats['lowest'],
                ];
            });
        }

        $sort = in_array($request->query('sort'), ['class_label', 'subject_name', 'average', 'highest', 'lowest', 'students'], true)
            ? $request->query('sort') : 'class_label';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $rows = $dir === 'desc' ? $rows->sortByDesc($sort)->values() : $rows->sortBy($sort)->values();

        return view('school.reports.subject-insights', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'terms' => $terms,
            'term' => $term,
            'classOptions' => $classOptions,
            'subjectOptions' => $subjectOptions,
            'classArmId' => $classArmId,
            'subjectId' => $subjectId,
            'fromDate' => $enteredFrom,
            'toDate' => $enteredTo,
            'sort' => $sort,
            'dir' => $dir,
            'rows' => $rows,
            'hasAnyTeachingAssignment' => $pairs->isNotEmpty(),
        ]);
    }

    protected function parseDate(?string $value): ?\Carbon\Carbon
    {
        if (! $value) {
            return null;
        }

        try {
            return \Carbon\Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
    }
}
