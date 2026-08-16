<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\Club;
use App\Models\Tenant\House;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Student;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Guided setup wizard (user request, tracker section 13/15, made into a
 * genuine step-by-step flow 2026-07-12 per the "To Do List 2" doc's item 1,
 * "School Setup Wizard"). One screen at a time rather than a single long
 * checklist, for a first-time admin who has never seen the product before.
 * Deliberately does not duplicate any setup logic, every action on this
 * page posts to the same existing routes those standalone screens already
 * use, this is a curated front door onto them, not a second
 * implementation. Each step reads as "done" straight from whether the
 * relevant records exist, no separate progress-tracking flag to fall out
 * of sync with reality. Curriculum type and Fees, both present in the
 * source "To Do List 2" wizard sketch, are deliberately not steps here,
 * neither has a real setting/module behind it yet (no curriculum-type
 * field exists; Finance is still deferred scope per CLAUDE.md).
 */
class SetupController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        $rbac = app(Rbac::class);
        $user = Auth::user();

        abort_unless($rbac->check($user, $school, 'academics.view'), 403, 'You do not have permission for this.');

        $hasSession = AcademicSession::exists();
        $hasClasses = Section::whereHas('classes')->exists();
        $hasHouses = House::exists();
        $hasClubs = Club::exists();
        $skipped = self::skippedSteps();
        $hasAssessment = AssessmentComponent::exists();
        $hasStudents = Student::exists();
        $hasStaff = Staff::exists();

        $allRealStepsDone = (bool) ($school->brand_color || $school->logo_path || $school->welcome_headline)
            && ($hasSession || in_array('session', $skipped, true))
            && ($hasClasses || in_array('classes', $skipped, true))
            && ($hasHouses || in_array('houses', $skipped, true))
            && ($hasAssessment || in_array('assessment', $skipped, true))
            && ($hasStudents || in_array('students', $skipped, true))
            && ($hasStaff || in_array('staff', $skipped, true));
        // $hasClubs is deliberately NOT part of this: clubs are optional,
        // so a school that does not run them must still be able to reach
        // a finished setup.

        $steps = [
            ['key' => 'welcome', 'title' => 'Welcome', 'done' => true],
            ['key' => 'branding', 'title' => 'Branding', 'done' => (bool) ($school->brand_color || $school->logo_path || $school->welcome_headline)],
            ['key' => 'session', 'title' => 'Session & term', 'done' => $hasSession],
            ['key' => 'classes', 'title' => 'Class structure', 'done' => $hasClasses],
            ['key' => 'houses', 'title' => 'Houses', 'done' => $hasHouses],
            ['key' => 'clubs', 'title' => 'Clubs', 'done' => $hasClubs],
            ['key' => 'assessment', 'title' => 'Assessment structure', 'done' => $hasAssessment],
            ['key' => 'students', 'title' => 'Students', 'done' => $hasStudents],
            ['key' => 'staff', 'title' => 'Staff', 'done' => $hasStaff],
            // "Done" only reads as complete on the rail once every real step above actually is, so it never
            // shows a checkmark that overstates progress while steps are still outstanding.
            ['key' => 'done', 'title' => 'Done', 'done' => $allRealStepsDone],
        ];

        $requested = (int) $request->query('step', 0);
        if ($requested >= 1 && $requested <= count($steps)) {
            $current = $requested - 1;
        } else {
            // Land on the first not-yet-done step, or the final "Done" step if everything is already set up.
            $current = collect($steps)->search(fn ($s) => ! $s['done']);
            $current = $current === false ? count($steps) - 1 : $current;
        }

        $steps = array_map(function ($step) use ($skipped) {
            $step['skipped'] = in_array($step['key'], $skipped, true);
            // A skipped step reads as settled in the rail, so the
            // wizard does not keep nagging about something the school
            // has already said it does not use.
            $step['done'] = $step['done'] || $step['skipped'];

            return $step;
        }, $steps);

        return view('school.setup', [
            'studentColumns' => (new \App\Services\Bulk\Importers\StudentImporter)->columns(),
            'mandatoryStudentColumns' => self::mandatoryStudentColumns(),
            'defaultHouses' => config('skulos.default_houses', []),
            'defaultClubs' => config('skulos.default_clubs', []),
            'defaultComponents' => config('skulos.default_assessment_components', []),
            'school' => $school,
            'accent' => $school->branding()['color'],
            'steps' => $steps,
            'current' => $current,
            'currentSession' => $hasSession ? AcademicSession::current()->first() : null,
            'classCount' => Section::withCount('classes')->get()->sum('classes_count'),
            'houseCount' => House::count(),
            'studentCount' => Student::count(),
            'staffCount' => Staff::count(),
            'classCatalogs' => config('skulos.default_class_structure'),
            'allDone' => collect($steps)->every(fn ($s) => $s['done']),
            'canManageAcademics' => $rbac->check($user, $school, 'academics.create'),
            'canManageStudentSettings' => $rbac->check($user, $school, 'students.manage_settings'),
            'canApproveResults' => $rbac->check($user, $school, 'examinations.approve_results'),
            'canManageBranding' => $rbac->check($user, $school, 'school_info.update'),
        ]);
    }

    /**
     * Steps a school has deliberately passed on (2026-08-16, direct
     * instruction: "a school admin can decide to skip a step if they
     * dont want to do it or use it"). Kept as a setting rather than
     * inferred from empty data, because "we have no houses yet" and "we
     * do not use houses" are different states and only the school knows
     * which is true.
     */
    public static function skippedSteps(): array
    {
        return (array) Setting::grab('setup_skipped_steps', []);
    }

    public function skipStep(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        abort_unless(app(Rbac::class)->check(Auth::user(), $school, 'academics.view'), 403);

        $data = $request->validate(['step' => ['required', 'string', 'max:40']]);

        $skipped = self::skippedSteps();
        // A second press un-skips, so this is never a one-way door.
        if (in_array($data['step'], $skipped, true)) {
            $skipped = array_values(array_diff($skipped, [$data['step']]));
            $message = 'Step restored.';
        } else {
            $skipped[] = $data['step'];
            $message = 'Step skipped. You can come back to it any time.';
        }
        Setting::put('setup_skipped_steps', array_values(array_unique($skipped)));

        return back()->with('status', $message);
    }

    /**
     * Create houses or clubs from the setup wizard: any mix of ticked
     * suggestions and names the school typed itself. Both paths exist
     * because a school either recognises its own houses in the standard
     * list or has entirely different ones, and forcing the defaults on
     * the second kind just means deleting four rows afterwards.
     */
    public function createGroups(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        abort_unless(app(Rbac::class)->check(Auth::user(), $school, 'students.manage_settings'), 403);

        $data = $request->validate([
            'kind' => ['required', 'in:houses,clubs'],
            'selected' => ['nullable', 'array'],
            'selected.*' => ['string', 'max:100'],
            'custom' => ['nullable', 'array'],
            'custom.*' => ['nullable', 'string', 'max:100'],
        ]);

        $names = collect($data['selected'] ?? [])
            ->merge($data['custom'] ?? [])
            ->map(fn ($n) => trim((string) $n))
            ->filter()
            ->unique(fn ($n) => mb_strtolower($n));

        if ($names->isEmpty()) {
            return back()->with('error', 'Pick at least one, or type your own.');
        }

        $model = $data['kind'] === 'houses' ? House::class : Club::class;
        $added = 0;
        foreach ($names as $name) {
            if (! $model::where('name', $name)->exists()) {
                $model::create(['name' => $name]);
                $added++;
            }
        }

        return back()->with('status', $added.' '.($data['kind'] === 'houses' ? 'house' : 'club').'(s) added.');
    }

    /**
     * Assessment components from the wizard, same idea: tick the
     * standard split or describe your own. Max scores are validated as
     * real numbers here rather than trusted, since these directly drive
     * every result the school will ever compute.
     */
    public function createAssessment(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        abort_unless(app(Rbac::class)->check(Auth::user(), $school, 'examinations.approve_results'), 403);

        $data = $request->validate([
            'components' => ['required', 'array', 'min:1'],
            'components.*.name' => ['nullable', 'string', 'max:60'],
            // Free text, not a fixed list (2026-08-16): a school that
            // runs neither "continuous assessment" nor "exam" -- tests,
            // projects, practicals -- should describe its own. The value
            // is only used to group components on the CBT linking
            // screen, never in any result calculation, so a custom one
            // is safe; it simply sits outside those two groups there.
            'components.*.type' => ['nullable', 'string', 'max:40'],
            'components.*.max_score' => ['nullable', 'numeric', 'min:1', 'max:100'],
        ]);

        $rows = collect($data['components'])
            ->filter(fn ($c) => filled($c['name'] ?? null) && filled($c['max_score'] ?? null));

        if ($rows->isEmpty()) {
            return back()->with('error', 'Give at least one component a name and a maximum score.');
        }

        $total = $rows->sum(fn ($c) => (float) $c['max_score']);
        if ($total > 100) {
            // Caught here rather than after saving: a structure adding
            // up to more than 100 silently breaks every percentage and
            // grade band downstream.
            return back()->with('error', "Those components add up to {$total} marks. They need to total 100 or less.");
        }

        $sequence = (int) AssessmentComponent::max('sequence');
        foreach ($rows as $row) {
            $name = trim($row['name']);
            if (AssessmentComponent::where('name', $name)->exists()) {
                continue;
            }
            AssessmentComponent::create([
                'name' => $name,
                // Normalised back to the two known keys when the text
                // matches one of them, so the standard options keep
                // grouping correctly instead of becoming near-duplicate
                // free text ("Exam" vs "exam").
                'type' => $this->normaliseComponentType($row['type'] ?? null),
                'max_score' => (float) $row['max_score'],
                'sequence' => ++$sequence,
            ]);
        }

        return back()->with('status', 'Assessment structure saved.');
    }

    /** Maps the familiar wording back to the stored keys, leaving anything else as typed. */
    protected function normaliseComponentType(?string $type): string
    {
        $value = mb_strtolower(trim((string) $type));

        return match (true) {
            $value === '' => 'ca',
            str_contains($value, 'continuous'), $value === 'ca' => 'ca',
            str_contains($value, 'exam') => 'exam',
            default => trim((string) $type),
        };
    }

    /**
     * A blank student import sheet containing only the columns a school
     * says it is ready to fill (2026-08-16). Asked up front rather than
     * handing over every column, because a school at setup usually has
     * names and classes to hand but not addresses or dates of birth,
     * and a sheet full of columns they cannot complete reads as work
     * they have failed to do.
     *
     * The columns the importer genuinely cannot proceed without --
     * admission number and the two name fields -- plus whatever the
     * school itself has marked required, are forced in regardless of
     * what was ticked, since a sheet missing those cannot be imported
     * at all and would only fail later.
     */
    public function studentTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $school = $this->school($request);
        abort_unless(app(Rbac::class)->check(Auth::user(), $school, 'students.create'), 403);

        $all = (new \App\Services\Bulk\Importers\StudentImporter)->columns();
        $mandatory = self::mandatoryStudentColumns();

        $chosen = collect($request->input('columns', []))->intersect(array_keys($all));
        $headers = collect(array_keys($all))
            ->filter(fn ($key) => in_array($key, $mandatory, true) || $chosen->contains($key))
            ->values();

        $out = fopen('php://temp', 'r+');
        fputcsv($out, $headers->map(fn ($key) => $all[$key])->all());
        // One blank row so the sheet opens with somewhere obvious to
        // start typing rather than a lone header line.
        fputcsv($out, array_fill(0, $headers->count(), ''));
        rewind($out);
        $csv = (string) stream_get_contents($out);
        fclose($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="student-import-'.$school->slug.'.csv"',
        ]);
    }

    /** Always present: without these the import cannot identify or create a student. */
    public static function mandatoryStudentColumns(): array
    {
        return array_values(array_unique(array_merge(
            ['admission_number', 'first_name', 'last_name'],
            (array) Setting::grab('student_required_fields', []),
        )));
    }
}
