<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentProfileChangeRequest;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Rbac\StudentAccessGuard;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A parent's requested change to their child's profile (user request
 * 2026-07-26: "parent should be able to edit their child details
 * (everything there except admission number, class and house) - class
 * teacher has to approve the changes first then the school admin also
 * have to do the final approval before it takes effect on the parent
 * and student details"). Two real, sequential decisions: the specific
 * class teacher of the student's current class arm, resolved once at
 * submission time, then any user holding students.manage_settings (the
 * same admin-tier bar StudentController::show()'s own canManageLogins
 * already uses for "genuinely admin, not just class-teacher-tier").
 * Admission number, class, and house are deliberately never editable
 * here, they stay an admin-only direct edit via StudentController::
 * update().
 */
class StudentProfileChangeController extends Controller
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

    /**
     * @return array<string, array<int, string>>
     */
    protected function editableFieldRules(): array
    {
        $required = Setting::grab('student_required_fields', []);
        $req = fn (string $field) => in_array($field, $required, true) ? 'required' : 'nullable';

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => [$req('other_names'), 'nullable', 'string', 'max:100'],
            'gender' => [$req('gender'), 'nullable', 'string', 'max:20'],
            'date_of_birth' => [$req('date_of_birth'), 'nullable', 'date'],
            'email' => ['nullable', 'email', 'max:150'],
            'parent_mobile' => [$req('parent_mobile'), 'nullable', 'string', 'max:40'],
            'country' => [$req('country'), 'nullable', 'string', 'max:100'],
            'state_of_origin' => [$req('state_of_origin'), 'nullable', 'string', 'max:100'],
            'lga' => [$req('lga'), 'nullable', 'string', 'max:100'],
            'address' => [$req('address'), 'nullable', 'string', 'max:500'],
        ];
    }

    protected function findStudentForParent(Request $request): Student
    {
        $school = $this->school($request);
        $user = Auth::user();
        $student = Student::with('currentClassArm.schoolClass')->findOrFail($request->route('studentId'));
        app(StudentAccessGuard::class)->assertVisible($user, $school, $student);

        $roleSlug = optional($user->roleIn($school))->slug;
        abort_unless($roleSlug === 'parent', 403, 'You do not have permission for this.');

        return $student;
    }

    public function create(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        $student = $this->findStudentForParent($request);

        $pending = StudentProfileChangeRequest::where('student_id', $student->id)
            ->whereNotIn('status', [StudentProfileChangeRequest::STATUS_APPROVED, StudentProfileChangeRequest::STATUS_REJECTED_BY_CLASS_TEACHER, StudentProfileChangeRequest::STATUS_REJECTED_BY_ADMIN])
            ->latest()
            ->first();

        return view('school.students.profile-change.create', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'student' => $student,
            'pending' => $pending,
            'lastDecided' => StudentProfileChangeRequest::where('student_id', $student->id)->latest()->first(),
            'requiredFields' => Setting::grab('student_required_fields', []),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $user = Auth::user();
        $student = $this->findStudentForParent($request);

        $alreadyPending = StudentProfileChangeRequest::where('student_id', $student->id)
            ->whereIn('status', [StudentProfileChangeRequest::STATUS_PENDING_CLASS_TEACHER, StudentProfileChangeRequest::STATUS_PENDING_ADMIN])
            ->exists();
        abort_if($alreadyPending, 422, 'A change request for this child is already awaiting a decision.');

        $data = $request->validate($this->editableFieldRules());

        // The gender <select>'s option values are lowercase ('male'/
        // 'female') but existing student records store it capitalized
        // ("Male"/"Female", same inconsistency StudentController's own
        // create/edit form already has, tracked in project memory as a
        // recurring gender-casing bug). Left uncorrected, that casing
        // mismatch makes gender look "changed" on every single
        // submission even when a parent never touched the field, a real
        // bug found live 2026-07-27. Normalized to the app's dominant
        // capitalized convention on both sides of the comparison, so the
        // stored request (and whatever admin approval ultimately writes
        // back to the student) never introduces a lowercase value.
        $normalize = fn (string $field, $value) => ($field === 'gender' && is_string($value) && $value !== '')
            ? ucfirst(strtolower($value))
            : $value;

        $data = collect($data)->map(fn ($value, $field) => $normalize($field, $value))->all();

        // Carbon-cast attributes (date_of_birth) must be flattened to the
        // same plain date string the form itself submits, both so the
        // stored "original" value renders as a readable date rather than
        // a raw ISO timestamp ("2018-02-19T00:00:00.000000Z", a real bug
        // found live 2026-07-27) and so array_diff_assoc below compares
        // like with like, an unformatted Carbon instance never string
        // equals its own form value and would falsely flag every
        // submission as a date_of_birth change even when untouched.
        $original = collect($data)->keys()->mapWithKeys(function ($field) use ($student, $normalize) {
            $value = $student->{$field};
            $value = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;

            return [$field => $normalize($field, $value)];
        })->all();
        $changed = array_diff_assoc($data, $original);

        if (empty($changed)) {
            return back()->with('status', 'Nothing changed.');
        }

        $classTeacherStaffId = $student->currentClassArm?->classTeacher?->id ?? $student->currentClassArm?->class_teacher_staff_id;

        $changeRequest = StudentProfileChangeRequest::create([
            'student_id' => $student->id,
            'submitted_by_user_id' => $user->id,
            'original' => array_intersect_key($original, $changed),
            'requested' => $changed,
            'status' => $classTeacherStaffId ? StudentProfileChangeRequest::STATUS_PENDING_CLASS_TEACHER : StudentProfileChangeRequest::STATUS_PENDING_ADMIN,
            'class_teacher_staff_id' => $classTeacherStaffId,
        ]);

        if ($classTeacherStaffId) {
            $this->notifyClassTeacher($school, $changeRequest, $student);
        } else {
            $this->notifyAdmins($school, $student, "{$student->fullName()}'s profile change is awaiting your review (no class teacher is assigned).");
        }

        return back()->with('status', "Your requested change for {$student->fullName()} has been sent for review, and will apply once approved.");
    }

    /**
     * Whether $user may reach the class-teacher review queue at all —
     * an admin, a real class-teacher/class-assistant, or a subject/plain
     * teacher an admin has explicitly given the 'students' extra-access
     * override (2026-08-05 correction: "subject teachers should not have
     * access to student profile change request... meant for only class
     * teachers and admin. This only shows if the subject teacher is
     * given the access"). Mirrors the nav's own $canReviewProfileChanges
     * gate exactly, so a direct URL hit can't bypass what the menu hides.
     */
    protected function canReviewClassTeacherQueue(User $user, School $school, bool $canManage): bool
    {
        if ($canManage) {
            return true;
        }
        $slug = optional($user->roleIn($school))->slug;
        if ($slug && in_array($slug, config('skulos.class_review_role_slugs', []), true)) {
            return true;
        }

        return $slug && in_array($slug, config('skulos.teacher_scoped_roles', []), true)
            && app(Rbac::class)->hasModuleOverride($school, $user, 'students');
    }

    /** Every pending-class-teacher request routed to the acting staff member's own assigned classes. */
    public function classTeacherIndex(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        $user = Auth::user();
        $staff = Staff::where('user_id', $user->id)->first();
        $canManage = app(Rbac::class)->check($user, $school, 'students.manage_settings');
        abort_unless($this->canReviewClassTeacherQueue($user, $school, $canManage), 403, 'You do not have permission for this.');

        $requests = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_CLASS_TEACHER)
            ->when(! $canManage, fn ($q) => $q->where('class_teacher_staff_id', $staff?->id))
            ->with('student', 'submittedBy')
            ->latest()
            ->get();

        return view('school.students.profile-change.class-teacher-index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'requests' => $requests,
        ]);
    }

    protected function canActAsClassTeacher(Request $request, StudentProfileChangeRequest $changeRequest): bool
    {
        $school = $this->school($request);
        $user = Auth::user();
        if (app(Rbac::class)->check($user, $school, 'students.manage_settings')) {
            return true;
        }
        $staff = Staff::where('user_id', $user->id)->first();

        return $staff && $staff->id === $changeRequest->class_teacher_staff_id;
    }

    public function classTeacherApprove(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $changeRequest = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_CLASS_TEACHER)
            ->with('student')->findOrFail($request->route('requestId'));
        abort_unless($this->canActAsClassTeacher($request, $changeRequest), 403, 'You do not have permission for this.');

        $changeRequest->update([
            'status' => StudentProfileChangeRequest::STATUS_PENDING_ADMIN,
            'class_teacher_decided_by_user_id' => Auth::id(),
            'class_teacher_decided_at' => now(),
        ]);

        $this->notifyAdmins($school, $changeRequest->student, "{$changeRequest->student->fullName()}'s profile change has been approved by the class teacher and is awaiting final admin approval.");

        return back()->with('status', "Sent on to the school admin for final approval.");
    }

    public function classTeacherReject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $changeRequest = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_CLASS_TEACHER)
            ->with('student')->findOrFail($request->route('requestId'));
        abort_unless($this->canActAsClassTeacher($request, $changeRequest), 403, 'You do not have permission for this.');

        $data = $request->validate(['notes' => ['required', 'string', 'max:500']]);

        $changeRequest->update([
            'status' => StudentProfileChangeRequest::STATUS_REJECTED_BY_CLASS_TEACHER,
            'class_teacher_decided_by_user_id' => Auth::id(),
            'class_teacher_decided_at' => now(),
            'class_teacher_notes' => $data['notes'],
        ]);

        $this->notifyApplicant($changeRequest, 'student_profile_change_rejected', 'Your requested change was rejected', "The change you requested for {$changeRequest->student->fullName()} was not approved by the class teacher: {$data['notes']}");

        return back()->with('status', 'The change request has been rejected.');
    }

    /** Bulk version of classTeacherApprove(), one submission per ticked row. */
    public function classTeacherBulkApprove(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $data = $request->validate([
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => ['exists:tenant.student_profile_change_requests,id'],
        ]);

        $requests = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_CLASS_TEACHER)
            ->whereIn('id', $data['request_ids'])->with('student')->get();

        $count = 0;
        foreach ($requests as $changeRequest) {
            if (! $this->canActAsClassTeacher($request, $changeRequest)) {
                continue;
            }
            $changeRequest->update([
                'status' => StudentProfileChangeRequest::STATUS_PENDING_ADMIN,
                'class_teacher_decided_by_user_id' => Auth::id(),
                'class_teacher_decided_at' => now(),
            ]);
            $this->notifyAdmins($school, $changeRequest->student, "{$changeRequest->student->fullName()}'s profile change has been approved by the class teacher and is awaiting final admin approval.");
            $count++;
        }

        return back()->with('status', "{$count} change request(s) sent on to the school admin for final approval.");
    }

    /** Bulk version of classTeacherReject(), one shared reason applied to every selected request. */
    public function classTeacherBulkReject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => ['exists:tenant.student_profile_change_requests,id'],
            'notes' => ['required', 'string', 'max:500'],
        ]);

        $requests = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_CLASS_TEACHER)
            ->whereIn('id', $data['request_ids'])->with('student')->get();

        $count = 0;
        foreach ($requests as $changeRequest) {
            if (! $this->canActAsClassTeacher($request, $changeRequest)) {
                continue;
            }
            $changeRequest->update([
                'status' => StudentProfileChangeRequest::STATUS_REJECTED_BY_CLASS_TEACHER,
                'class_teacher_decided_by_user_id' => Auth::id(),
                'class_teacher_decided_at' => now(),
                'class_teacher_notes' => $data['notes'],
            ]);
            $this->notifyApplicant($changeRequest, 'student_profile_change_rejected', 'Your requested change was rejected', "The change you requested for {$changeRequest->student->fullName()} was not approved by the class teacher: {$data['notes']}");
            $count++;
        }

        return back()->with('status', "{$count} change request(s) rejected.");
    }

    /** Every request awaiting final admin approval. */
    public function adminIndex(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $school = $this->school($request);

        $requests = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_ADMIN)
            ->with('student', 'submittedBy', 'classTeacherStaff')
            ->latest()
            ->get();

        return view('school.students.profile-change.admin-index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'requests' => $requests,
        ]);
    }

    public function adminApprove(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $changeRequest = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_ADMIN)
            ->with('student')->findOrFail($request->route('requestId'));

        $changeRequest->student->update($changeRequest->requested);
        $changeRequest->update([
            'status' => StudentProfileChangeRequest::STATUS_APPROVED,
            'admin_decided_by_user_id' => Auth::id(),
            'admin_decided_at' => now(),
        ]);

        $this->notifyApplicant($changeRequest, 'student_profile_change_approved', 'Your requested change was approved', "The change you requested for {$changeRequest->student->fullName()} has been approved and is now live.");

        return back()->with('status', "{$changeRequest->student->fullName()}'s profile has been updated.");
    }

    public function adminReject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $changeRequest = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_ADMIN)
            ->with('student')->findOrFail($request->route('requestId'));

        $data = $request->validate(['notes' => ['required', 'string', 'max:500']]);

        $changeRequest->update([
            'status' => StudentProfileChangeRequest::STATUS_REJECTED_BY_ADMIN,
            'admin_decided_by_user_id' => Auth::id(),
            'admin_decided_at' => now(),
            'admin_notes' => $data['notes'],
        ]);

        $this->notifyApplicant($changeRequest, 'student_profile_change_rejected', 'Your requested change was rejected', "The change you requested for {$changeRequest->student->fullName()} was not approved: {$data['notes']}");

        return back()->with('status', 'The change request has been rejected.');
    }

    /** Bulk version of adminApprove(), applies every selected request's changes directly. */
    public function adminBulkApprove(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $data = $request->validate([
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => ['exists:tenant.student_profile_change_requests,id'],
        ]);

        $requests = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_ADMIN)
            ->whereIn('id', $data['request_ids'])->with('student')->get();

        foreach ($requests as $changeRequest) {
            $changeRequest->student->update($changeRequest->requested);
            $changeRequest->update([
                'status' => StudentProfileChangeRequest::STATUS_APPROVED,
                'admin_decided_by_user_id' => Auth::id(),
                'admin_decided_at' => now(),
            ]);
            $this->notifyApplicant($changeRequest, 'student_profile_change_approved', 'Your requested change was approved', "The change you requested for {$changeRequest->student->fullName()} has been approved and is now live.");
        }

        return back()->with('status', "{$requests->count()} student profile(s) updated.");
    }

    /** Bulk version of adminReject(), one shared reason applied to every selected request. */
    public function adminBulkReject(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $data = $request->validate([
            'request_ids' => ['required', 'array', 'min:1'],
            'request_ids.*' => ['exists:tenant.student_profile_change_requests,id'],
            'notes' => ['required', 'string', 'max:500'],
        ]);

        $requests = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_ADMIN)
            ->whereIn('id', $data['request_ids'])->with('student')->get();

        foreach ($requests as $changeRequest) {
            $changeRequest->update([
                'status' => StudentProfileChangeRequest::STATUS_REJECTED_BY_ADMIN,
                'admin_decided_by_user_id' => Auth::id(),
                'admin_decided_at' => now(),
                'admin_notes' => $data['notes'],
            ]);
            $this->notifyApplicant($changeRequest, 'student_profile_change_rejected', 'Your requested change was rejected', "The change you requested for {$changeRequest->student->fullName()} was not approved: {$data['notes']}");
        }

        return back()->with('status', "{$requests->count()} change request(s) rejected.");
    }

    protected function notifyClassTeacher(School $school, StudentProfileChangeRequest $changeRequest, Student $student): void
    {
        $staff = Staff::find($changeRequest->class_teacher_staff_id);
        if (! $staff?->user_id) {
            return;
        }
        Notification::create([
            'user_id' => $staff->user_id,
            'category' => 'student_profile_change_pending_class_teacher',
            'type' => Notification::typeFor('student_profile_change_pending_class_teacher'),
            'title' => 'Student profile change awaiting your review',
            'body' => "{$student->fullName()}'s parent has requested a profile change. Review it under Students > Change Requests.",
        ]);
    }

    protected function notifyAdmins(School $school, Student $student, string $body): void
    {
        $roleIds = Role::where('school_id', $school->id)
            ->whereHas('permissions', fn ($q) => $q->where('code', 'students.manage_settings'))
            ->pluck('id');

        $userIds = DB::table('school_user')
            ->where('school_id', $school->id)->where('status', 'active')
            ->whereIn('role_id', $roleIds)->pluck('user_id');

        foreach ($userIds->unique() as $userId) {
            Notification::create([
                'user_id' => $userId,
                'category' => 'student_profile_change_pending_admin',
                'type' => Notification::typeFor('student_profile_change_pending_admin'),
                'title' => 'Student profile change awaiting your review',
                'body' => $body,
            ]);
        }
    }

    protected function notifyApplicant(StudentProfileChangeRequest $changeRequest, string $category, string $title, string $body): void
    {
        if ($applicant = User::find($changeRequest->submitted_by_user_id)) {
            Notification::create([
                'user_id' => $applicant->id,
                'category' => $category,
                'type' => Notification::typeFor($category),
                'title' => $title,
                'body' => $body,
            ]);
        }
    }
}
