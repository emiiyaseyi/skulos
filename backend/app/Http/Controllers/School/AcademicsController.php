<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
use App\Services\Academics\AcademicDefaults;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Sections, classes, and which subjects each class offers (mandatory or
 * optional, examinable or not) — user request 2026-07-09: SS1-3 style
 * classes do not put every student on every subject. A school can also
 * one-click load a starting subject list, sourced from the school's own
 * default catalogs in config/skulos.php; it remains fully editable
 * afterwards.
 *
 * Class/subject ids are read via $request->route(...) rather than typed
 * method parameters. Because these routes sit under a domain wildcard
 * ({school}.<domain>), Laravel resolves untyped/scalar controller
 * parameters positionally against ALL route parameters including the
 * domain one, not by name — a method like update($request, $classId)
 * silently receives the school slug in $classId instead of the intended
 * value. Reading via $request->route('classId') sidesteps that entirely.
 */
class AcademicsController extends Controller
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

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'academics.view');
        $school = $this->school($request);

        $sections = Section::with(['classes' => function ($q) {
            $q->withCount('subjects')->orderBy('sequence');
        }])->orderBy('sequence')->get();

        $sessions = AcademicSession::with(['terms' => fn ($q) => $q->orderBy('sequence')])
            ->orderByDesc('name')
            ->get();

        return view('school.academics.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'sections' => $sections,
            'sessions' => $sessions,
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'academics.update'),
        ]);
    }

    /**
     * One click: creates an academic session named "{start}/{end} Session"
     * (Nigerian convention, user request 2026-07-10) with its three terms
     * "First/Second/Third Term {start}/{end}" already attached. A school
     * that runs a different number of terms can still add/remove terms
     * individually afterwards via storeTerm(); nothing here forces exactly
     * three, it is just the fast path for the common case.
     */
    public function storeSession(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.create');
        $data = $request->validate([
            'start_year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $startYear = (int) $data['start_year'];
        $yearLabel = $startYear.'/'.($startYear + 1);
        $name = "{$yearLabel} Session";

        if (AcademicSession::where('name', $name)->exists()) {
            return back()->with('error', "{$name} already exists.");
        }

        $session = AcademicSession::create(['name' => $name]);
        foreach (['First' => 1, 'Second' => 2, 'Third' => 3] as $ordinal => $sequence) {
            Term::create([
                'academic_session_id' => $session->id,
                'name' => "{$ordinal} Term {$yearLabel}",
                'sequence' => $sequence,
            ]);
        }

        if (! AcademicSession::current()->exists()) {
            $session->makeCurrent();
        }

        return back()->with('status', "{$name} created with its three terms.");
    }

    public function makeSessionCurrent(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $session = AcademicSession::findOrFail($request->route('sessionId'));
        $session->makeCurrent();

        return back()->with('status', "{$session->name} is now the current session.");
    }

    /** Add an extra/custom term to a session, e.g. a school running semesters instead of three terms. */
    public function storeTerm(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.create');
        $data = $request->validate([
            'academic_session_id' => ['required', 'exists:tenant.academic_sessions,id'],
            'name' => ['required', 'string', 'max:80'],
            'sequence' => ['nullable', 'integer', 'min:1'],
        ]);

        Term::create([
            'academic_session_id' => $data['academic_session_id'],
            'name' => $data['name'],
            'sequence' => $data['sequence'] ?? ((int) Term::where('academic_session_id', $data['academic_session_id'])->max('sequence') + 1),
        ]);

        return back()->with('status', 'Term added.');
    }

    public function makeTermCurrent(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $term = Term::findOrFail($request->route('termId'));
        $term->makeCurrent();

        return back()->with('status', "{$term->name} is now the current term.");
    }

    public function storeSection(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:80'],
            'sequence' => ['nullable', 'integer', 'min:1'],
        ]);
        Section::create([
            'name' => $data['name'],
            'sequence' => $data['sequence'] ?? ((int) Section::max('sequence') + 1),
        ]);

        return back()->with('status', 'Section added.');
    }

    public function storeClass(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.create');
        $data = $request->validate([
            'section_id' => ['required', 'exists:tenant.sections,id'],
            'name' => ['required', 'string', 'max:80'],
            'sequence' => ['nullable', 'integer', 'min:1'],
        ]);
        SchoolClass::create([
            'section_id' => $data['section_id'],
            'name' => $data['name'],
            'sequence' => $data['sequence'] ?? 1,
        ]);

        return back()->with('status', 'Class added.');
    }

    public function subjects(Request $request, AcademicDefaults $defaults): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'academics.view');
        $school = $this->school($request);
        $class = SchoolClass::with('section')->findOrFail($request->route('classId'));

        return view('school.academics.subjects', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'class' => $class,
            'offered' => $class->subjects()->orderBy('name')->get(),
            'available' => Subject::whereNotIn('id', $class->subjects()->pluck('subjects.id'))->orderBy('name')->get(),
            'suggestedCatalog' => $defaults->suggestCatalogFor($class->section),
            'catalogs' => config('skulos.default_subjects'),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'academics.update'),
        ]);
    }

    public function applyDefaults(Request $request, AcademicDefaults $defaults): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $data = $request->validate(['catalog' => ['required', 'string', 'in:'.implode(',', array_keys(config('skulos.default_subjects')))]]);

        $class = SchoolClass::findOrFail($request->route('classId'));
        $count = $defaults->applySubjectCatalog($class, $data['catalog']);

        return back()->with('status', $count > 0 ? "{$count} subject(s) added from the default list." : 'Every subject in that list is already offered by this class.');
    }

    public function attachSubject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $data = $request->validate([
            'subject_id' => ['required', 'exists:tenant.subjects,id'],
        ]);

        $class = SchoolClass::findOrFail($request->route('classId'));
        $class->subjects()->syncWithoutDetaching([
            $data['subject_id'] => [
                'is_mandatory' => $request->boolean('is_mandatory', true),
                'is_examinable' => $request->boolean('is_examinable', true),
            ],
        ]);

        return back()->with('status', 'Subject added to this class.');
    }

    public function newSubject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        $class = SchoolClass::findOrFail($request->route('classId'));
        $subject = Subject::firstOrCreate(['name' => $data['name']], ['code' => $data['code'] ?? null]);
        $class->subjects()->syncWithoutDetaching([
            $subject->id => ['is_mandatory' => true, 'is_examinable' => true],
        ]);

        return back()->with('status', "\"{$subject->name}\" added to this class.");
    }

    public function updateSubject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $class = SchoolClass::findOrFail($request->route('classId'));
        $subject = Subject::findOrFail($request->route('subjectId'));

        $class->subjects()->updateExistingPivot($subject->id, [
            'is_mandatory' => $request->boolean('is_mandatory'),
            'is_examinable' => $request->boolean('is_examinable'),
        ]);

        return back()->with('status', "Updated {$subject->name}.");
    }

    public function detachSubject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $class = SchoolClass::findOrFail($request->route('classId'));
        $subject = Subject::findOrFail($request->route('subjectId'));
        $class->subjects()->detach($subject->id);

        return back()->with('status', "Removed {$subject->name} from this class.");
    }
}
