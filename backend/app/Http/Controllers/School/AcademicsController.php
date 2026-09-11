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
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Services\Academics\AcademicDefaults;
use App\Services\Academics\PromotionService;
use App\Services\Attendance\AttendanceService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

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
    public function storeSession(Request $request, AcademicDefaults $defaults): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.create');
        $data = $request->validate([
            'start_year' => ['required', 'integer', 'min:2000', 'max:2100'],
        ]);

        $session = $defaults->createSessionWithTerms((int) $data['start_year']);
        if (! $session) {
            $startYear = (int) $data['start_year'];

            return back()->with('error', "{$startYear}/".($startYear + 1)." Session already exists.");
        }

        if (! AcademicSession::current()->exists()) {
            $session->makeCurrent();
        }

        return back()->with('status', "{$session->name} created with its three terms.");
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
    public function endTerm(Request $request, PromotionService $promotions, AcademicDefaults $defaults): \Illuminate\Http\RedirectResponse
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
        $nextStartYear = $defaults->nextStartYearAfter($currentTerm->session);

        // createSessionWithTerms() returns null only when that session
        // already exists (e.g. an admin created next year's session
        // ahead of time), in which case just use it.
        $nextSessionName = "{$nextStartYear}/".($nextStartYear + 1).' Session';
        $nextSession = $defaults->createSessionWithTerms($nextStartYear)
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
    public function editCalendar(Request $request, AttendanceService $attendance): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'academics.view');
        $school = $this->school($request);

        $sessions = AcademicSession::active()
            ->with(['terms' => fn ($q) => $q->orderBy('sequence')])
            ->orderByDesc('name')
            ->get();

        // A live preview next to each term's own days-open field (user
        // request 2026-07-24: "admin can also check the calculated date
        // and make adjustment to the number auto calculated"), computed
        // from that term's own start/end dates, not the stored value, so
        // the admin can compare what is actually saved against what the
        // calendar would produce right now.
        $calculatedSchoolDays = [];
        foreach ($sessions->flatMap->terms as $term) {
            if ($term->starts_on && $term->ends_on) {
                $calculatedSchoolDays[$term->id] = $attendance->schoolDaysBetween($term->starts_on, $term->ends_on);
            }
        }

        $archivedSessions = AcademicSession::where('is_archived', true)
            ->with(['terms' => fn ($q) => $q->orderBy('sequence')])
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
            'archivedSessions' => $archivedSessions,
            'suggestedTerm' => $suggestedTerm,
            'calculatedSchoolDays' => $calculatedSchoolDays,
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'academics.update'),
        ]);
    }

    /** Hides a done session from the default list (Dbase recommendation: archive old sessions, exclude from default queries). */
    public function archiveSession(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $session = AcademicSession::findOrFail($request->route('sessionId'));

        try {
            $session->archive();
        } catch (\RuntimeException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('status', "{$session->name} archived.");
    }

    public function unarchiveSession(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $session = AcademicSession::findOrFail($request->route('sessionId'));
        $session->unarchive();

        return back()->with('status', "{$session->name} restored.");
    }

    /** Bulk version of unarchiveSession(), same list-screen bulk-action pattern used across the app. */
    public function bulkUnarchiveSessions(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $data = $request->validate([
            'session_ids' => ['required', 'array', 'min:1'],
            'session_ids.*' => ['exists:tenant.academic_sessions,id'],
        ]);

        $sessions = AcademicSession::whereIn('id', $data['session_ids'])->get();
        foreach ($sessions as $session) {
            $session->unarchive();
        }

        return back()->with('status', "{$sessions->count()} session(s) restored.");
    }

    /**
     * Optional start/end dates for a term, used to auto-suggest the
     * current term by today's date. "Days school was open" (report card
     * Physical & Attendance) is auto-calculated from those same dates,
     * weekends and holiday/break calendar events excluded (user request
     * 2026-07-24: "this is calculated from the term start date and end
     * date... admin can also check the calculated date and make
     * adjustment to the number auto calculated"): leaving the field
     * blank recalculates it fresh every save, typing an explicit number
     * is a deliberate override that survives future date edits until the
     * admin clears it back to blank.
     */
    public function updateTermDates(Request $request, AttendanceService $attendance): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $term = Term::findOrFail($request->route('termId'));
        $data = $request->validate([
            'starts_on' => ['nullable', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'total_school_days' => ['nullable', 'integer', 'min:1', 'max:250'],
        ]);

        $startsOn = $data['starts_on'] ?? $term->starts_on;
        $endsOn = $data['ends_on'] ?? $term->ends_on;
        if (empty($data['total_school_days']) && $startsOn && $endsOn) {
            $data['total_school_days'] = $attendance->schoolDaysBetween(Carbon::parse($startsOn), Carbon::parse($endsOn));
        }

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
     * Rename a section or move it to a different position (user request
     * 2026-07-12, "Class Order & Structure.docx"): there was previously no
     * way to fix a section's own order once created, only a class's — a
     * school whose section names do not match the built in Nursery/
     * Primary/Secondary catalog (a custom curriculum) had no way to put
     * its own sections in the right order at all.
     */
    public function updateSection(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $section = Section::findOrFail($request->route('sectionId'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('tenant.sections', 'name')->ignore($section->id)],
            'sequence' => ['nullable', 'integer', 'min:1'],
        ]);

        $section->update([
            'name' => $data['name'],
            'sequence' => $data['sequence'] ?? $section->sequence,
        ]);

        return back()->with('status', "\"{$section->name}\" updated.");
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

    /** Rename a class or move it to a different sequence position (user request 2026-07-11). */
    public function updateClass(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $class = SchoolClass::findOrFail($request->route('classId'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:80', Rule::unique('tenant.school_classes', 'name')->where('section_id', $class->section_id)->ignore($class->id)],
            'sequence' => ['nullable', 'integer', 'min:1'],
        ]);

        $class->update([
            'name' => $data['name'],
            'sequence' => $data['sequence'] ?? $class->sequence,
        ]);

        return back()->with('status', "\"{$class->name}\" updated.");
    }

    /** Retire (hide from new enrollment/loaders) or reactivate a class without deleting it. */
    public function toggleClassActive(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $class = SchoolClass::findOrFail($request->route('classId'));
        $class->update(['is_active' => ! $class->is_active]);

        return back()->with('status', $class->is_active
            ? "\"{$class->name}\" is active again."
            : "\"{$class->name}\" is retired. Existing students keep their assignment, but it can no longer be selected for new enrollment.");
    }

    /**
     * Refuses to delete a class with any currently enrolled student,
     * across any of its streams (user request 2026-07-11: "there is no
     * way to delete... a class"), the same refuse-rather-than-silently-
     * unassign posture already used for a House with members and an
     * AssessmentComponent with scores. An admin who really means it
     * retires the class or moves its students out first.
     */
    public function destroyClass(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $school = $this->school($request);
        $class = SchoolClass::with('arms')->findOrFail($request->route('classId'));

        $armIds = $class->arms->pluck('id');
        if (Student::whereIn('current_class_arm_id', $armIds)->exists()) {
            return back()->with('error', "\"{$class->name}\" has students currently enrolled and cannot be removed. Move or retire its students first.");
        }

        $name = $class->name;
        $class->delete();

        // Real bug (user report 2026-09-09): the "Remove" form lives on
        // this class's own show page, and back() redirects to wherever
        // the request CAME from -- that same page, which now findOrFail()s
        // on a class that no longer exists, 404ing immediately (the
        // delete itself had already succeeded, only the redirect target
        // was wrong -- pressing the browser's own back button afterward
        // landed on the class list showing it correctly gone). Redirect
        // to a page guaranteed to still exist instead of trusting the
        // Referer.
        return redirect()->route('school.academics.index', ['school' => $school->slug])
            ->with('status', "\"{$name}\" removed.");
    }

    /**
     * Full detail for one class (user request 2026-07-11): every stream,
     * its class teacher and assistant class teacher, the subjects it
     * offers, and who teaches each one (via TeachingAssignment, scoped to
     * this class's own streams). No student roster (removed 2026-07-25,
     * user report: "under classes & subjects, the student name/list
     * should not be seen... remove the student list section as it is a
     * repetition of the student portal section") — the roster lives on
     * the Students screen, this page is class structure only.
     */
    public function showClass(Request $request): \Illuminate\View\View
    {
        // Serves both the Classes & Subjects area and the Students list's
        // "view" link, so either permission suffices, not just
        // academics.view (found 2026-07-11: a subject-teacher can view
        // Students but not Classes & Subjects).
        $school = $this->school($request);
        $allowed = app(Rbac::class)->check(Auth::user(), $school, 'academics.view')
            || app(Rbac::class)->check(Auth::user(), $school, 'students.view');
        abort_unless($allowed, 403, 'You do not have permission for this.');
        $class = SchoolClass::with(['section', 'arms.arm', 'arms.classTeacher', 'arms.assistantTeacher', 'subjects'])
            ->findOrFail($request->route('classId'));

        $armIds = $class->arms->pluck('id');
        $assignmentsBySubject = TeachingAssignment::whereIn('class_arm_id', $armIds)
            ->with('staff', 'subject')
            ->get()
            ->groupBy('subject_id');
        $teachersBySubject = $assignmentsBySubject->map(fn ($group) => $group->pluck('staff')->filter()->unique('id')->map->fullName()->implode(', '));
        // First assigned staff id per subject, used to pre-select the
        // single "assign teacher" dropdown (the same teacher is applied
        // across every stream of the class, see assignSubjectTeacher()).
        $staffIdBySubject = $assignmentsBySubject->map(fn ($group) => $group->first()?->staff_id);

        // The class roster (2026-08-16, direct request): the page listed
        // the class's subjects and teachers but never its students,
        // which is the first thing anyone opening a class expects.
        //
        // Scoped, not blanket: a teacher restricted to their own class
        // arms sees only the arms they actually teach, so opening
        // another class shows its structure without its children's
        // names. FamilyScope is applied for the same reason -- a parent
        // reaching here sees only their own child.
        $user = Auth::user();
        $visibleArmIds = $armIds;
        $teacherArmIds = app(\App\Services\Rbac\TeacherScope::class)->classArmIds($user, $school);
        if ($teacherArmIds !== null) {
            $visibleArmIds = $armIds->intersect($teacherArmIds);
        }
        $familyStudentIds = app(\App\Services\Rbac\FamilyScope::class)->studentIds($user, $school);

        $students = Student::active()
            ->whereIn('current_class_arm_id', $visibleArmIds)
            ->when($familyStudentIds !== null, fn ($q) => $q->whereIn('id', $familyStudentIds))
            ->with('currentClassArm.arm', 'guardians')
            ->orderBy('last_name')->orderBy('first_name')
            ->get();

        return view('school.academics.class-show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'class' => $class,
            'teachersBySubject' => $teachersBySubject,
            'staffIdBySubject' => $staffIdBySubject,
            'staffOptions' => Staff::active()->orderBy('first_name')->get(),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'academics.update'),
            'students' => $students,
            // Two separate permissions, because seeing a child's own
            // record and seeing their family's contact details are
            // genuinely different levels of access.
            'canViewStudents' => app(Rbac::class)->check($user, $school, 'students.view'),
            'canViewParents' => app(Rbac::class)->check($user, $school, 'parents.view'),
            // Acting on students is a stricter permission than seeing them.
            'canManageStudents' => app(Rbac::class)->check($user, $school, 'students.update'),
            // Bulk import (2026-09-11, "upload bulk students on a class
            // by class basis too") is its own permission, not folded
            // into canManageStudents -- a role could plausibly hold one
            // without the other.
            'canImportStudents' => app(Rbac::class)->check($user, $school, 'students.bulk'),
            'rosterIsScoped' => $teacherArmIds !== null || $familyStudentIds !== null,
        ]);
    }

    /** Assign or clear a stream's class teacher or assistant class teacher. */
    public function assignArmTeacher(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $arm = ClassArm::findOrFail($request->route('armId'));

        $data = $request->validate([
            'role' => ['required', 'in:class_teacher,assistant_teacher'],
            'staff_id' => ['nullable', 'exists:tenant.staff,id'],
        ]);

        $column = $data['role'] === 'class_teacher' ? 'class_teacher_staff_id' : 'assistant_teacher_staff_id';
        $arm->update([$column => $data['staff_id'] ?: null]);

        return back()->with('status', 'Updated.');
    }

    /**
     * Assign or clear who teaches one subject across every stream of a
     * class in one action (user request 2026-07-11: "one should be able
     * to click on the subject and select the subject teacher"). Applies
     * the same teacher to every arm of the class rather than one teacher
     * per arm per subject, matching how the request describes it, a
     * class with several streams most commonly shares one subject
     * teacher across them.
     */
    public function assignSubjectTeacher(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $class = SchoolClass::with('arms')->findOrFail($request->route('classId'));
        $subject = Subject::findOrFail($request->route('subjectId'));

        $data = $request->validate(['staff_id' => ['nullable', 'exists:tenant.staff,id']]);
        $armIds = $class->arms->pluck('id');

        TeachingAssignment::where('subject_id', $subject->id)->whereIn('class_arm_id', $armIds)->delete();

        if (! empty($data['staff_id'])) {
            foreach ($armIds as $armId) {
                TeachingAssignment::create([
                    'staff_id' => $data['staff_id'],
                    'subject_id' => $subject->id,
                    'class_arm_id' => $armId,
                ]);
            }
        }

        return back()->with('status', "{$subject->name} updated.");
    }

    /** Pre-filled CSV: class, subject, and the currently assigned teacher (blank if none). */
    public function subjectTeachersTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'academics.view');

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['class', 'subject', 'teacher_staff_id']);

        foreach (SchoolClass::with(['arms', 'subjects'])->orderBy('sequence')->get() as $class) {
            $armIds = $class->arms->pluck('id');
            foreach ($class->subjects as $subject) {
                $staffId = TeachingAssignment::where('subject_id', $subject->id)
                    ->whereIn('class_arm_id', $armIds)
                    ->value('staff_id');
                fputcsv($out, [$class->name, $subject->name, $staffId ?? '']);
            }
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="subject-teachers.csv"',
        ]);
    }

    /**
     * Re-upload of the template above (user request 2026-07-11: "there
     * should be a bulk csv file to do this too"). Matches class and
     * subject by exact name (case insensitive) within this school's own
     * records, teacher by staff_id (the template's own column, round
     * trip friendly, not a name match prone to duplicates). A blank
     * teacher cell clears that assignment rather than being skipped,
     * this is a full "set it to this" round trip, not a partial fill in
     * like the Houses bulk assign (there is no ambiguous "leave
     * untouched" case here since every row is always exactly one class
     * plus one subject).
     */
    public function bulkAssignSubjectTeachers(Request $request, \App\Services\Bulk\CsvReader $reader): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $csv = file_get_contents($request->file('file')->getRealPath());
        try {
            $rows = $reader->rows($csv);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $classesByName = SchoolClass::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => $id])->all();

        $updated = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $className = trim((string) ($row['class'] ?? ''));
            $subjectName = trim((string) ($row['subject'] ?? ''));
            $staffId = trim((string) ($row['teacher_staff_id'] ?? ''));

            $classId = $classesByName[mb_strtolower($className)] ?? null;
            if (! $classId) {
                $errors[] = "Row {$rowNumber}: class \"{$className}\" was not found.";

                continue;
            }

            $class = SchoolClass::with('arms')->find($classId);
            $subject = Subject::whereRaw('lower(name) = ?', [mb_strtolower($subjectName)])->first();
            if (! $subject || ! $class->subjects()->where('subjects.id', $subject->id)->exists()) {
                $errors[] = "Row {$rowNumber}: subject \"{$subjectName}\" is not offered by \"{$className}\".";

                continue;
            }

            if ($staffId !== '' && ! Staff::whereKey($staffId)->exists()) {
                $errors[] = "Row {$rowNumber}: no staff with id \"{$staffId}\".";

                continue;
            }

            $armIds = $class->arms->pluck('id');
            TeachingAssignment::where('subject_id', $subject->id)->whereIn('class_arm_id', $armIds)->delete();
            if ($staffId !== '') {
                foreach ($armIds as $armId) {
                    TeachingAssignment::create(['staff_id' => (int) $staffId, 'subject_id' => $subject->id, 'class_arm_id' => $armId]);
                }
            }
            $updated++;
        }

        $status = "{$updated} row(s) updated.";
        if ($errors) {
            $status .= ' '.count($errors).' row(s) had problems: '.implode(' ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' ...' : '');

            return back()->with('error', $status);
        }

        return back()->with('status', $status);
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

    /**
     * Renames one specific stream (user report 2026-07-21: "I renamed the
     * first class stream to Primary 1 silver and the main class name
     * added silver to it and the second stream also added silver to it.
     * Each stream should be a unique class under the main class name").
     * There was previously no way to rename a stream at all, only to add
     * new ones, so the reported "rename" was actually the user editing
     * the CLASS's own name field (updateClass() above) hoping it would
     * scope to one stream — instead correctly renaming the whole class,
     * which every stream's label is built from.
     *
     * This deliberately never mutates arms.name directly: Arm rows are
     * shared/reused across classes by design (storeArm()'s own
     * firstOrCreate), so renaming the row in place would silently rename
     * every OTHER class's stream that happens to share the same label
     * (e.g. another class's own "Gold" stream) — the exact "second
     * stream also added silver to it" shape of bug, just from the other
     * direction. Reassigning this one ClassArm to a find-or-created Arm
     * matching the new name is the same safe pattern storeArm() already
     * uses, and never touches any other class's stream.
     */
    public function renameArm(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $classArm = ClassArm::findOrFail($request->route('classArmId'));
        $data = $request->validate(['name' => ['required', 'string', 'max:40']]);

        $newArm = Arm::firstOrCreate(['name' => $data['name']]);

        $duplicate = ClassArm::where('school_class_id', $classArm->school_class_id)
            ->where('arm_id', $newArm->id)
            ->where('id', '!=', $classArm->id)
            ->exists();
        if ($duplicate) {
            return back()->with('error', "This class already has a \"{$newArm->name}\" stream.");
        }

        $classArm->update(['arm_id' => $newArm->id]);

        return back()->with('status', "Stream renamed to {$newArm->name}.");
    }

    /**
     * A class with more than one named stream (SSS's Arts & Humanities /
     * Science & Technology / Commercial, most commonly) needs its own
     * subject list per stream, not one list every student in the class
     * sees regardless of which stream they are actually in — see
     * ClassArm::offeredSubjects(). $classArmId (query param on the GET,
     * hidden field on every form here) switches every operation below
     * onto that one stream's own class_arm_subjects list instead of the
     * whole class's class_subject list; omitted entirely, this screen
     * behaves exactly as it always has for the vast majority of classes
     * that only ever have one stream.
     */
    protected function subjectScope(Request $request, SchoolClass $class): array
    {
        $armId = $request->integer('class_arm_id') ?: null;
        $arm = $armId ? ClassArm::where('school_class_id', $class->id)->with('arm')->findOrFail($armId) : null;

        return [$arm, $arm ? $arm->subjects() : $class->subjects()];
    }

    public function subjects(Request $request, AcademicDefaults $defaults): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'academics.view');
        $school = $this->school($request);
        $class = SchoolClass::with(['section', 'arms'])->findOrFail($request->route('classId'));
        $streams = ClassArm::where('school_class_id', $class->id)->whereNotNull('arm_id')->with('arm')->get()
            ->sortBy(fn (ClassArm $a) => $a->orderKey());
        [$selectedArm, $subjectsRelation] = $this->subjectScope($request, $class);

        // Teacher assignment, right here too (2026-09-11, user directive
        // "let's also make it possible to assign a teacher to each
        // subject in a class view") -- reuses the exact same
        // assignSubjectTeacher() action class-show.blade.php's own
        // "Subjects offered" table already posts to, so the two screens
        // can never disagree about who teaches what. That action is
        // deliberately whole-class (every stream gets the same teacher
        // for a subject, confirmed existing behaviour, not something
        // this change alters), which is why the picker below is shown
        // regardless of which stream tab is selected.
        $armIds = $class->arms->pluck('id');
        $staffIdBySubject = TeachingAssignment::whereIn('class_arm_id', $armIds)
            ->get()->groupBy('subject_id')->map(fn ($group) => $group->first()?->staff_id);

        return view('school.academics.subjects', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'class' => $class,
            'streams' => $streams,
            'selectedArm' => $selectedArm,
            'offered' => $subjectsRelation->get(),
            'available' => Subject::whereNotIn('id', $subjectsRelation->pluck('subjects.id'))->orderBy('sequence')->orderBy('name')->get(),
            'suggestedCatalog' => $selectedArm ? null : $defaults->suggestCatalogFor($class->section),
            'catalogs' => config('skulos.default_subjects'),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'academics.update'),
            'staffOptions' => Staff::active()->orderBy('first_name')->get(),
            'staffIdBySubject' => $staffIdBySubject,
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
        [, $subjectsRelation] = $this->subjectScope($request, $class);
        $subjectsRelation->syncWithoutDetaching([
            $data['subject_id'] => [
                'is_mandatory' => $request->boolean('is_mandatory', true),
                'is_examinable' => $request->boolean('is_examinable', true),
            ],
        ]);

        return back()->with('status', 'Subject added.');
    }

    public function newSubject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'code' => ['nullable', 'string', 'max:20'],
        ]);

        $class = SchoolClass::findOrFail($request->route('classId'));
        [, $subjectsRelation] = $this->subjectScope($request, $class);
        $subject = Subject::firstOrCreate(
            ['name' => $data['name']],
            ['code' => $data['code'] ?? null, 'sequence' => (int) Subject::max('sequence') + 1]
        );
        $subjectsRelation->syncWithoutDetaching([
            $subject->id => ['is_mandatory' => true, 'is_examinable' => true],
        ]);

        return back()->with('status', "\"{$subject->name}\" added.");
    }

    /** Also lets an admin manually move a subject to a different position in its list (user request 2026-07-12, "Class Order & Structure.docx"), the same "rename or move" pattern as updateClass(). */
    public function updateSubject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $class = SchoolClass::findOrFail($request->route('classId'));
        [, $subjectsRelation] = $this->subjectScope($request, $class);
        $subject = Subject::findOrFail($request->route('subjectId'));

        $data = $request->validate(['sequence' => ['nullable', 'integer', 'min:1']]);

        $subjectsRelation->updateExistingPivot($subject->id, [
            'is_mandatory' => $request->boolean('is_mandatory'),
            'is_examinable' => $request->boolean('is_examinable'),
        ]);
        if (isset($data['sequence'])) {
            $subject->update(['sequence' => $data['sequence']]);
        }

        return back()->with('status', "Updated {$subject->name}.");
    }

    public function detachSubject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $class = SchoolClass::findOrFail($request->route('classId'));
        [, $subjectsRelation] = $this->subjectScope($request, $class);
        $subject = Subject::findOrFail($request->route('subjectId'));
        $subjectsRelation->detach($subject->id);

        return back()->with('status', "Removed {$subject->name}.");
    }

    /** Bulk version of detachSubject(), same list-screen bulk-action pattern used across the app. */
    public function bulkDetachSubjects(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'academics.update');
        $data = $request->validate([
            'subject_ids' => ['required', 'array', 'min:1'],
            'subject_ids.*' => ['exists:tenant.subjects,id'],
        ]);

        $class = SchoolClass::findOrFail($request->route('classId'));
        [, $subjectsRelation] = $this->subjectScope($request, $class);
        $subjectsRelation->detach($data['subject_ids']);

        return back()->with('status', count($data['subject_ids']).' subject(s) removed.');
    }
}
