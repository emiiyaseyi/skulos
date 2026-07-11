<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
use App\Services\Academics\AcademicDefaults;
use App\Services\Academics\PromotionService;
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
        }, 'classes.arms.arm'])->orderBy('sequence')->get();

        return view('school.academics.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'sections' => $sections,
            'classCatalogs' => config('skulos.default_class_structure'),
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

        $session = $this->createSessionWithTerms((int) $data['start_year']);
        if (! $session) {
            $startYear = (int) $data['start_year'];

            return back()->with('error', "{$startYear}/".($startYear + 1)." Session already exists.");
        }

        if (! AcademicSession::current()->exists()) {
            $session->makeCurrent();
        }

        return back()->with('status', "{$session->name} created with its three terms.");
    }

    /**
     * Shared by storeSession() and the End of Term session rollover:
     * creates "{start}/{end} Session" with its three Nigerian-named
     * terms if it does not already exist. Returns null (does not create
     * anything) if a session of that name already exists, since the two
     * callers handle "already exists" differently (one is a user error,
     * the other means "just use the one that is already there").
     */
    protected function createSessionWithTerms(int $startYear): ?AcademicSession
    {
        $yearLabel = $startYear.'/'.($startYear + 1);
        $name = "{$yearLabel} Session";

        if (AcademicSession::where('name', $name)->exists()) {
            return null;
        }

        $session = AcademicSession::create(['name' => $name]);
        foreach (['First' => 1, 'Second' => 2, 'Third' => 3] as $ordinal => $sequence) {
            Term::create([
                'academic_session_id' => $session->id,
                'name' => "{$ordinal} Term {$yearLabel}",
                'sequence' => $sequence,
            ]);
        }

        return $session;
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

    /**
     * End the current term (user request 2026-07-11). Moves to the next
     * term in the same session if one exists; on the last term of a
     * session, rolls over to the next session (creating it, and its
     * three terms, if it does not exist yet) and runs automatic student
     * promotion for the session that just ended, via PromotionService.
     * Gated behind academics.update, the same permission "make current"
     * already requires, since only owner/admin/principal/vice-principal/
     * ict-officer hold any academics.* permission at all, no classroom
     * teacher can trigger this.
     */
    public function endTerm(Request $request, PromotionService $promotions): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');

        $currentTerm = Term::current()->with('session')->first();
        abort_unless($currentTerm, 404, 'There is no current term to end.');

        $nextTerm = Term::where('academic_session_id', $currentTerm->academic_session_id)
            ->where('sequence', '>', $currentTerm->sequence)
            ->orderBy('sequence')
            ->first();

        if ($nextTerm) {
            $nextTerm->makeCurrent();

            return back()->with('status', "{$currentTerm->name} has ended. {$nextTerm->name} is now current.");
        }

        // Last term of the session: roll over and promote.
        preg_match('/(\d{4})/', $currentTerm->session->name, $matches);
        $nextStartYear = ((int) ($matches[1] ?? now()->year)) + 1;

        // createSessionWithTerms() returns null only when that session
        // already exists (e.g. an admin created next year's session
        // ahead of time), in which case just use it.
        $nextSessionName = "{$nextStartYear}/".($nextStartYear + 1).' Session';
        $nextSession = $this->createSessionWithTerms($nextStartYear)
            ?? AcademicSession::where('name', $nextSessionName)->first();
        abort_unless($nextSession, 500, 'Could not determine the next session.');

        $nextSession->makeCurrent();
        $nextFirstTerm = Term::where('academic_session_id', $nextSession->id)->orderBy('sequence')->first();
        $nextFirstTerm?->makeCurrent();

        $result = $promotions->promote($currentTerm);

        return back()->with('status',
            "{$currentTerm->session->name} has ended. {$nextSession->name} is now current. ".
            "{$result['promoted']} student(s) promoted, {$result['repeated']} repeating their class, {$result['graduated']} graduated.");
    }

    /**
     * Sessions and terms live under Settings (user request 2026-07-11:
     * this is one-time setup information, not a day-to-day Classes &
     * Subjects concern). Also surfaces a "today falls under this term"
     * suggestion when terms have their start/end dates filled in, so an
     * admin who has entered the school calendar does not have to work out
     * which term is current by hand; a school that has not entered dates
     * simply picks a session and term manually via the existing
     * "Make current" buttons, both are supported.
     */
    public function editCalendar(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'academics.view');
        $school = $this->school($request);

        $sessions = AcademicSession::with(['terms' => fn ($q) => $q->orderBy('sequence')])
            ->orderByDesc('name')
            ->get();

        $today = now()->toDateString();
        $suggestedTerm = Term::whereNotNull('starts_on')
            ->whereNotNull('ends_on')
            ->whereDate('starts_on', '<=', $today)
            ->whereDate('ends_on', '>=', $today)
            ->where('is_current', false)
            ->with('session')
            ->first();

        return view('school.settings-academic-calendar', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'sessions' => $sessions,
            'suggestedTerm' => $suggestedTerm,
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'academics.update'),
        ]);
    }

    /** Optional start/end dates for a term, used to auto-suggest the current term by today's date. */
    public function updateTermDates(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $term = Term::findOrFail($request->route('termId'));
        $data = $request->validate([
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
        ]);

        $term->update($data);

        return back()->with('status', "Dates saved for {$term->name}.");
    }

    public function editPromotionSettings(Request $request, PromotionService $promotions): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'academics.view');
        $school = $this->school($request);

        return view('school.academics.promotion-settings', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'threshold' => $promotions->defaultThreshold(),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'academics.update'),
        ]);
    }

    public function updatePromotionSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $data = $request->validate([
            'promotion_threshold' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        Setting::put('promotion_threshold', (float) $data['promotion_threshold']);

        return back()->with('status', 'Promotion requirement updated.');
    }

    /**
     * One click, one or more tiers: ticking Nursery/Primary/JSS/SS creates
     * each tier's Section, classes (each with a default ClassArm), and
     * default subjects together (user request 2026-07-10), rather than
     * building a class at a time and separately loading subjects per
     * class as the existing flow requires.
     */
    public function applyClassStructure(Request $request, AcademicDefaults $defaults): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.create');
        $data = $request->validate([
            'tiers' => ['required', 'array', 'min:1'],
            'tiers.*' => ['string', 'in:'.implode(',', array_keys(config('skulos.default_class_structure')))],
        ]);

        $result = $defaults->applyClassStructure($data['tiers']);

        return back()->with('status', "{$result['classes']} class(es) and {$result['subjects']} subject(s) added.");
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

    /**
     * A class needs at least one ClassArm to actually hold students
     * (Student.current_class_arm_id points at a ClassArm, not a
     * SchoolClass) — creating one here with arm_id null (a single,
     * unnamed stream) means a brand new class is immediately usable
     * without forcing every school to name an arm it doesn't need.
     * Schools that do run multiple streams add named ones via storeArm().
     */
    public function storeClass(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.create');
        $data = $request->validate([
            'section_id' => ['required', 'exists:tenant.sections,id'],
            'name' => ['required', 'string', 'max:80'],
            'sequence' => ['nullable', 'integer', 'min:1'],
        ]);
        $class = SchoolClass::create([
            'section_id' => $data['section_id'],
            'name' => $data['name'],
            'sequence' => $data['sequence'] ?? 1,
        ]);
        ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);

        return back()->with('status', 'Class added.');
    }

    /**
     * Add a named stream/arm to a class, e.g. "B" on "JSS 1" once a
     * school outgrows a single stream. Find-or-create the Arm by name so
     * the same label (e.g. "A") is reused across classes rather than
     * duplicated per class.
     */
    /**
     * Adding the first named stream to a class that only has the default
     * single (unnamed) stream converts that existing ClassArm into the
     * new named one, rather than creating a second row alongside it
     * (user request 2026-07-11, closing the "known rough edge" noted
     * when the default ClassArm was introduced). This keeps the ClassArm
     * id stable, so any student already on it stays correctly assigned,
     * it is a rename, not a new enrollment. Once a class has any named
     * stream, further additions create new rows as before, there is no
     * more single default stream left to convert.
     */
    public function storeArm(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.create');
        $data = $request->validate([
            'school_class_id' => ['required', 'exists:tenant.school_classes,id'],
            'name' => ['required', 'string', 'max:40'],
        ]);

        $class = SchoolClass::findOrFail($data['school_class_id']);
        $arm = Arm::firstOrCreate(['name' => $data['name']]);

        if (ClassArm::where('school_class_id', $class->id)->where('arm_id', $arm->id)->exists()) {
            return back()->with('error', "{$class->name} {$arm->name} already exists.");
        }

        $existingArms = ClassArm::where('school_class_id', $class->id)->get();
        $defaultStream = $existingArms->count() === 1 ? $existingArms->first() : null;

        if ($defaultStream && $defaultStream->arm_id === null) {
            $defaultStream->update(['arm_id' => $arm->id]);

            return back()->with('status', "{$class->name} is now split into streams, starting with {$arm->name}.");
        }

        ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);

        return back()->with('status', "{$class->name} {$arm->name} added.");
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
