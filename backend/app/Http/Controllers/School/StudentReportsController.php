<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use App\Services\Rbac\TeacherScope;
use App\Services\Results\ResultService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Student Reports (Foundation plan feature 'student_reports', built
 * 2026-07-18: the one remaining Foundation gap identified 2026-07-17,
 * previously only ever a "Soon" nav line). A class-wide broadsheet, per
 * subject scores plus total/average/position for every student in one
 * class arm and term, so staff can see how a whole class performed
 * without opening each student's report card individually.
 * ResultService::classBroadsheet() already existed (position/ranking
 * with tie handling included) but was never wired into any screen before
 * this. Same class-arm picker shape as AttendanceController::index(),
 * TeacherScope-restricted the same way.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class StudentReportsController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }

    public function index(Request $request, ResultService $results): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.view_broadsheet');
        $school = $this->school($request);
        $user = Auth::user();

        $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);

        $classArms = ClassArm::with('schoolClass.section', 'arm')
            ->when($scopedArmIds !== null, fn ($q) => $q->whereIn('id', $scopedArmIds))
            ->get()
            ->sortBy(fn (ClassArm $arm) => $arm->orderKey())
            ->values();

        $classArmId = $request->integer('class_arm_id') ?: null;
        if ($classArmId && $scopedArmIds !== null && ! $scopedArmIds->contains($classArmId)) {
            abort(404);
        }
        $selectedArm = $classArmId ? $classArms->firstWhere('id', $classArmId) : null;
        if ($classArmId && ! $selectedArm) {
            abort(404);
        }

        $terms = Term::with('session')->orderByDesc('starts_on')->get();
        $termId = $request->integer('term_id') ?: null;
        $term = ($termId ? Term::find($termId) : null) ?? Term::current()->first();

        $rows = collect();
        $subjects = collect();
        $insights = null;
        if ($selectedArm && $term) {
            $rows = collect($results->classBroadsheet($selectedArm->id, $term->id));
            $subjects = \App\Models\Tenant\Subject::whereIn('id', $rows->flatMap(fn ($r) => array_keys($r['subjects']))->unique())
                ->orderBy('name')->pluck('name', 'id');

            // Insights (user request 2026-07-18: "add insights from all
            // data such as under term sheet broadsheets too").
            if ($rows->isNotEmpty()) {
                $insights = [
                    'students' => $rows->count(),
                    'average' => round($rows->avg('average'), 1),
                    'highest' => $rows->max('total'),
                    'lowest' => $rows->min('total'),
                    'top' => $rows->firstWhere('position', 1),
                ];
            }

            // Sort (user request: "no sort and filter options"). Position
            // is the meaningful default (already ranked, ties handled);
            // any other sort is a plain re-order for browsing, not a
            // re-ranking.
            $sort = in_array($request->query('sort'), ['name', 'total', 'average'], true) ? $request->query('sort') : 'position';
            $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';
            $rows = $dir === 'desc' ? $rows->sortByDesc($sort)->values() : $rows->sortBy($sort)->values();
        }

        // Group by section (user request: "group by section and
        // classes"): the class picker is grouped into <optgroup>s by
        // section rather than one long flat list.
        $classArmsBySection = $classArms->groupBy(fn (ClassArm $arm) => $arm->schoolClass?->section?->name ?? 'Other');

        return view('school.reports.students', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'classArms' => $classArms,
            'classArmsBySection' => $classArmsBySection,
            'selectedArm' => $selectedArm,
            'terms' => $terms,
            'term' => $term,
            'rows' => $rows,
            'subjects' => $subjects,
            'insights' => $insights,
            'sort' => $sort ?? 'position',
            'dir' => $dir ?? 'asc',
        ]);
    }

    /** CSV export of the same broadsheet the index screen shows (matches the standing "export what I'm looking at" pattern StudentController::export() already uses). */
    public function export(Request $request, ResultService $results): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'examinations.view_broadsheet');
        $school = $this->school($request);
        $user = Auth::user();

        $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);
        $classArmId = $request->integer('class_arm_id');
        $arm = ClassArm::with('schoolClass.section', 'arm')->findOrFail($classArmId);
        if ($scopedArmIds !== null && ! $scopedArmIds->contains($arm->id)) {
            abort(404);
        }

        $termId = $request->integer('term_id') ?: null;
        $term = ($termId ? Term::find($termId) : null) ?? Term::current()->first();
        abort_unless($term, 404, 'There is no current term set.');

        $rows = collect($results->classBroadsheet($arm->id, $term->id));
        $subjectIds = $rows->flatMap(fn ($r) => array_keys($r['subjects']))->unique();
        $subjectNames = \App\Models\Tenant\Subject::whereIn('id', $subjectIds)->orderBy('name')->pluck('name', 'id');

        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_merge(['Position', 'Student'], $subjectNames->values()->all(), ['Total', 'Average']));
        foreach ($rows as $row) {
            $line = [$row['position'], $row['name']];
            foreach ($subjectNames as $subjectId => $name) {
                $line[] = $row['subjects'][$subjectId] ?? 0;
            }
            $line[] = $row['total'];
            $line[] = $row['average'];
            fputcsv($out, $line);
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="student-report-'.$arm->label().'-'.now()->toDateString().'.csv"',
        ]);
    }
}
