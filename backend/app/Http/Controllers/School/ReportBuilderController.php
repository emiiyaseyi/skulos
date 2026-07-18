<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Services\Rbac\Rbac;
use App\Services\Rbac\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Custom Report Builder (Advance plan feature 'custom_report_builder',
 * built 2026-07-18): pick a data source, pick which columns to include,
 * apply a filter, preview a table, export the same thing as CSV. Scoped
 * down from the docx's full "drag-and-drop custom report creation across
 * all modules" ambition to a real, buildable first cut across the two
 * sources that make sense today (Students, Staff); the catalog of
 * available sources/columns lives in config('skulos.report_builder_
 * sources') since these are real column definitions tied to real model
 * attributes, not something a school customizes further.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class ReportBuilderController extends Controller
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

    /** Which sources this user actually has the underlying permission to pull data from. */
    protected function availableSources(Request $request): array
    {
        $school = $this->school($request);
        $user = Auth::user();

        return collect(config('skulos.report_builder_sources', []))
            ->filter(fn ($source) => app(Rbac::class)->check($user, $school, $source['permission']))
            ->all();
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'analytics.view');
        $school = $this->school($request);
        $sources = $this->availableSources($request);

        $source = $request->query('source');
        $source = isset($sources[$source]) ? $source : null;
        $selectedColumns = $source ? array_values(array_intersect($request->query('columns', []), array_keys($sources[$source]['columns']))) : [];
        $classArmId = $request->integer('class_arm_id') ?: null;
        $status = $request->query('status');

        $rows = collect();
        if ($source && count($selectedColumns)) {
            $this->authorizeAction($request, $sources[$source]['permission']);
            $rows = $this->fetchRows($request, $source, $selectedColumns, $classArmId, $status);
        }

        $classArms = collect();
        if ($source === 'students') {
            $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
            $classArms = ClassArm::with('schoolClass.section', 'arm')
                ->when($scopedArmIds !== null, fn ($q) => $q->whereIn('id', $scopedArmIds))
                ->get()->sortBy(fn (ClassArm $a) => $a->orderKey())->values();
        }

        return view('school.reports.builder', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'sources' => $sources,
            'source' => $source,
            'selectedColumns' => $selectedColumns,
            'classArmId' => $classArmId,
            'status' => $status,
            'classArms' => $classArms,
            'rows' => $rows,
        ]);
    }

    public function export(Request $request): \Illuminate\Http\Response
    {
        $this->authorizeAction($request, 'analytics.view');
        $sources = $this->availableSources($request);

        $source = $request->query('source');
        abort_unless(isset($sources[$source]), 404);
        $this->authorizeAction($request, $sources[$source]['permission']);

        $selectedColumns = array_values(array_intersect($request->query('columns', []), array_keys($sources[$source]['columns'])));
        abort_if(empty($selectedColumns), 422, 'Choose at least one column first.');

        $rows = $this->fetchRows($request, $source, $selectedColumns, $request->integer('class_arm_id') ?: null, $request->query('status'));

        $out = fopen('php://temp', 'r+');
        fputcsv($out, array_map(fn ($key) => $sources[$source]['columns'][$key], $selectedColumns));
        foreach ($rows as $row) {
            fputcsv($out, array_map(fn ($key) => $row[$key] ?? '', $selectedColumns));
        }
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        $school = $this->school($request);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$source.'-report-'.$school->slug.'-'.now()->toDateString().'.csv"',
        ]);
    }

    /**
     * @param array<int, string> $columns
     * @return \Illuminate\Support\Collection<int, array<string, mixed>>
     */
    protected function fetchRows(Request $request, string $source, array $columns, ?int $classArmId, ?string $status): \Illuminate\Support\Collection
    {
        $school = $this->school($request);
        $user = Auth::user();

        if ($source === 'students') {
            $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);
            $query = Student::with('currentClassArm.schoolClass.section', 'currentClassArm.arm', 'house')
                ->when($scopedArmIds !== null, fn ($q) => $q->whereIn('current_class_arm_id', $scopedArmIds))
                ->when($classArmId, fn ($q) => $q->where('current_class_arm_id', $classArmId))
                ->when($status, fn ($q) => $q->where('status', $status))
                ->orderBy('last_name');

            return $query->get()->map(fn (Student $s) => $this->rowFor($s, $columns, [
                'class' => fn () => $s->currentClassArm?->label(),
                'house' => fn () => $s->house?->name,
            ]));
        }

        $query = Staff::query()
            ->when($status, fn ($q) => $q->where('status', $status))
            ->orderBy('last_name');

        return $query->get()->map(fn (Staff $s) => $this->rowFor($s, $columns, []));
    }

    /**
     * @param array<int, string> $columns
     * @param array<string, \Closure> $resolvers extra column keys not directly on the model
     */
    protected function rowFor(Student|Staff $model, array $columns, array $resolvers): array
    {
        $row = [];
        foreach ($columns as $column) {
            if (isset($resolvers[$column])) {
                $row[$column] = $resolvers[$column]();

                continue;
            }
            $value = $model->{$column} ?? null;
            $row[$column] = $value instanceof \Illuminate\Support\Carbon ? $value->format('d M Y') : $value;
        }

        return $row;
    }
}
