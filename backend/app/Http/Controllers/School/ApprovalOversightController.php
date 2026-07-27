<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\Notification;
use App\Models\Tenant\PasswordResetRequest;
use App\Models\Tenant\ProfileChangeRequest;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\Staff;
use App\Models\Tenant\StudentProfileChangeRequest;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;

/**
 * A single admin oversight screen across every "someone is waiting on a
 * decision" flow in the app (user request 2026-07-27: "admin should have
 * an approval page where the admin can see all approval request sent to
 * admin, sent to other users, class teachers, report card reviewers, and
 * any one, and from there, the admin can nudge the pending reviewer or
 * use bulk nudge... admin can also bypass user and approve on their
 * behalf... a sub approval section showing all approval requests, who
 * approved, what was requested or approved, time stamp of request and
 * approval etc").
 *
 * Deliberately does not re-implement each type's own approve/reject
 * logic, every "Approve"/"Reject" button here posts straight to that
 * type's own already-built, already-tested route (ProfileController,
 * StudentProfileChangeController, ReportCardController); a genuine admin
 * role already holds every permission those routes individually check,
 * so acting from here already IS "bypass the assigned person and decide
 * on their behalf". What this controller adds is the merged view across
 * all four types, in one paginated list, plus a real Nudge action for
 * the two types that never had one at all (ProfileChangeRequest/
 * PasswordResetRequest, StudentProfileChangeRequest).
 */
class ApprovalOversightController extends Controller
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

    /** @return array<int, array<string, mixed>> */
    protected function pendingItems(School $school, Rbac $rbac): array
    {
        $user = Auth::user();
        $canApproveStaff = $rbac->check($user, $school, 'staff.update');
        $canApproveStudentAdmin = $rbac->check($user, $school, 'students.manage_settings');
        $canApproveReportCards = $rbac->check($user, $school, 'report_cards.assign_review');

        $items = collect();

        foreach (ProfileChangeRequest::where('status', ProfileChangeRequest::STATUS_PENDING)->with('applicant')->get() as $r) {
            $items->push([
                'type' => 'profile_change', 'typeLabel' => 'Profile Change', 'id' => $r->id,
                'requestedBy' => $r->applicant?->name ?? 'Unknown', 'pendingWith' => 'Any admin (staff access)',
                'description' => 'Requested changes to their own name, email, or phone',
                'submittedAt' => $r->created_at,
                'approveRoute' => $canApproveStaff ? route('school.profile.approve', ['school' => $school->slug, 'requestId' => $r->id]) : null,
                'rejectRoute' => $canApproveStaff ? route('school.profile.reject', ['school' => $school->slug, 'requestId' => $r->id]) : null,
                'nudgeKey' => "profile_change:{$r->id}",
            ]);
        }

        foreach (PasswordResetRequest::where('status', PasswordResetRequest::STATUS_PENDING)->with('applicant')->get() as $r) {
            $items->push([
                'type' => 'password_reset', 'typeLabel' => 'Password Reset', 'id' => $r->id,
                'requestedBy' => $r->applicant?->name ?? 'Unknown', 'pendingWith' => 'Any admin (staff access)',
                'description' => 'Requested a forgotten-password reset',
                'submittedAt' => $r->created_at,
                'approveRoute' => $canApproveStaff ? route('school.profile.reset.approve', ['school' => $school->slug, 'requestId' => $r->id]) : null,
                'rejectRoute' => $canApproveStaff ? route('school.profile.reset.reject', ['school' => $school->slug, 'requestId' => $r->id]) : null,
                'nudgeKey' => "password_reset:{$r->id}",
            ]);
        }

        $studentChanges = StudentProfileChangeRequest::whereIn('status', [
            StudentProfileChangeRequest::STATUS_PENDING_CLASS_TEACHER, StudentProfileChangeRequest::STATUS_PENDING_ADMIN,
        ])->with('student', 'submittedBy', 'classTeacherStaff')->get();
        foreach ($studentChanges as $r) {
            $atTeacherStage = $r->status === StudentProfileChangeRequest::STATUS_PENDING_CLASS_TEACHER;
            $items->push([
                'type' => $atTeacherStage ? 'student_change_teacher' : 'student_change_admin',
                'typeLabel' => 'Student Profile Change', 'id' => $r->id,
                'requestedBy' => $r->submittedBy?->name ?? 'Unknown',
                'pendingWith' => $atTeacherStage ? ($r->classTeacherStaff?->fullName() ?? 'No class teacher assigned') : 'School admin',
                'description' => "Requested changes to {$r->student?->fullName()}'s profile",
                'submittedAt' => $r->created_at,
                'approveRoute' => $atTeacherStage
                    ? ($canApproveStudentAdmin ? route('school.students.profile-change.class-teacher.approve', ['school' => $school->slug, 'requestId' => $r->id]) : null)
                    : ($canApproveStudentAdmin ? route('school.students.profile-change.admin.approve', ['school' => $school->slug, 'requestId' => $r->id]) : null),
                'rejectRoute' => $atTeacherStage
                    ? ($canApproveStudentAdmin ? route('school.students.profile-change.class-teacher.reject', ['school' => $school->slug, 'requestId' => $r->id]) : null)
                    : ($canApproveStudentAdmin ? route('school.students.profile-change.admin.reject', ['school' => $school->slug, 'requestId' => $r->id]) : null),
                'nudgeKey' => $atTeacherStage ? "student_change_teacher:{$r->id}" : "student_change_admin:{$r->id}",
            ]);
        }

        $reviews = ReportCardReview::whereIn('status', [ReportCardReview::STATUS_PENDING_REVIEW, ReportCardReview::STATUS_NEEDS_CORRECTION])
            ->with('student', 'assignedTo', 'term')->get();
        foreach ($reviews as $r) {
            $items->push([
                'type' => 'report_card_review', 'typeLabel' => 'Report Card Review', 'id' => $r->id,
                'requestedBy' => $r->student?->fullName() ?? 'Unknown', 'pendingWith' => $r->assignedTo?->fullName() ?? 'Unassigned',
                'description' => "{$r->student?->fullName()}'s {$r->term?->name} report card, stage {$r->review_stage}",
                'submittedAt' => $r->updated_at,
                'approveRoute' => $canApproveReportCards ? route('school.report-cards.approve', ['school' => $school->slug, 'studentId' => $r->student_id, 'term_id' => $r->term_id]) : null,
                // No reject route here on purpose: sendBack() needs a
                // reassigned staff picker too, a field this simple
                // unified table has no room for, that action stays on
                // the dedicated Pending review screen this row links to.
                'rejectRoute' => null,
                'manageRoute' => route('school.report-cards.pending-review', ['school' => $school->slug]),
                'nudgeKey' => "report_card_review:{$r->id}",
            ]);
        }

        return $items->sortBy('submittedAt')->values()->all();
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $school = $this->school($request);

        $items = $this->pendingItems($school, app(Rbac::class));
        $page = (int) $request->query('page', 1);
        $perPage = 25;
        $paginated = new LengthAwarePaginator(
            array_slice($items, ($page - 1) * $perPage, $perPage),
            count($items), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('school.approvals.index', [
            'school' => $school, 'accent' => $school->branding()['color'], 'items' => $paginated,
        ]);
    }

    /** @return array<int, array<string, mixed>> */
    protected function historyItems(School $school): array
    {
        $items = collect();

        foreach (ProfileChangeRequest::whereIn('status', [ProfileChangeRequest::STATUS_APPROVED, ProfileChangeRequest::STATUS_REJECTED])
            ->with('applicant', 'reviewer')->latest('reviewed_at')->limit(200)->get() as $r) {
            $items->push([
                'typeLabel' => 'Profile Change', 'requestedBy' => $r->applicant?->name ?? 'Unknown',
                'decidedBy' => $r->reviewer?->name ?? 'Unknown', 'outcome' => $r->status,
                'submittedAt' => $r->created_at, 'decidedAt' => $r->reviewed_at, 'notes' => $r->notes,
            ]);
        }

        foreach (PasswordResetRequest::whereIn('status', [PasswordResetRequest::STATUS_APPROVED, PasswordResetRequest::STATUS_REJECTED])
            ->with('applicant')->latest('reviewed_at')->limit(200)->get() as $r) {
            $reviewer = $r->reviewed_by_user_id ? \App\Models\User::find($r->reviewed_by_user_id) : null;
            $items->push([
                'typeLabel' => 'Password Reset', 'requestedBy' => $r->applicant?->name ?? 'Unknown',
                'decidedBy' => $reviewer?->name ?? 'Unknown', 'outcome' => $r->status,
                'submittedAt' => $r->created_at, 'decidedAt' => $r->reviewed_at, 'notes' => $r->notes,
            ]);
        }

        $decided = StudentProfileChangeRequest::whereIn('status', [
            StudentProfileChangeRequest::STATUS_APPROVED, StudentProfileChangeRequest::STATUS_REJECTED_BY_CLASS_TEACHER, StudentProfileChangeRequest::STATUS_REJECTED_BY_ADMIN,
        ])->with('student', 'submittedBy')->latest('updated_at')->limit(200)->get();
        foreach ($decided as $r) {
            $decidedByUserId = $r->admin_decided_by_user_id ?? $r->class_teacher_decided_by_user_id;
            $decidedBy = $decidedByUserId ? \App\Models\User::find($decidedByUserId) : null;
            $items->push([
                'typeLabel' => 'Student Profile Change', 'requestedBy' => $r->submittedBy?->name ?? 'Unknown',
                'decidedBy' => $decidedBy?->name ?? 'Unknown', 'outcome' => $r->status,
                'submittedAt' => $r->created_at, 'decidedAt' => $r->admin_decided_at ?? $r->class_teacher_decided_at,
                'notes' => $r->admin_notes ?? $r->class_teacher_notes,
                'description' => "{$r->student?->fullName()}'s profile",
            ]);
        }

        foreach (ReportCardReview::whereIn('status', [ReportCardReview::STATUS_RELEASED, ReportCardReview::STATUS_LOCKED, ReportCardReview::STATUS_ARCHIVED])
            ->with('student', 'term')->latest('updated_at')->limit(200)->get() as $r) {
            $items->push([
                'typeLabel' => 'Report Card Review', 'requestedBy' => $r->student?->fullName() ?? 'Unknown',
                'decidedBy' => 'N/A', 'outcome' => $r->status,
                'submittedAt' => $r->created_at, 'decidedAt' => $r->released_at ?? $r->updated_at, 'notes' => null,
                'description' => "{$r->term?->name} report card",
            ]);
        }

        return $items->sortByDesc('decidedAt')->values()->all();
    }

    public function history(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $school = $this->school($request);

        $items = $this->historyItems($school);
        $page = (int) $request->query('page', 1);
        $perPage = 25;
        $paginated = new LengthAwarePaginator(
            array_slice($items, ($page - 1) * $perPage, $perPage),
            count($items), $perPage, $page,
            ['path' => $request->url(), 'query' => $request->query()]
        );

        return view('school.approvals.history', [
            'school' => $school, 'accent' => $school->branding()['color'], 'items' => $paginated,
        ]);
    }

    public function nudge(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $data = $request->validate(['key' => ['required', 'string']]);
        $ok = $this->nudgeOne($request, $data['key']);

        return back()->with($ok ? 'status' : 'error', $ok ? 'Nudge sent.' : 'Could not send that nudge.');
    }

    public function bulkNudge(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $data = $request->validate(['keys' => ['required', 'array', 'min:1']]);

        $count = 0;
        foreach (array_unique($data['keys']) as $key) {
            if ($this->nudgeOne($request, $key)) {
                $count++;
            }
        }

        return back()->with('status', "{$count} nudge(s) sent.");
    }

    /** Every active user holding staff.update, the same audience ProfileController's own notifyApprovers() already targets. */
    protected function notifyStaffUpdateHolders(School $school, string $title, string $body): void
    {
        $roleIds = Role::where('school_id', $school->id)
            ->whereHas('permissions', fn ($q) => $q->where('code', 'staff.update'))
            ->pluck('id');

        $userIds = DB::table('school_user')
            ->where('school_id', $school->id)->where('status', 'active')
            ->whereIn('role_id', $roleIds)->pluck('user_id');

        foreach ($userIds->unique() as $userId) {
            Notification::create([
                'user_id' => $userId, 'title' => $title, 'body' => $body,
                'category' => 'profile_approval', 'type' => Notification::typeFor('profile_approval'),
            ]);
        }
    }

    /** Every active user holding students.manage_settings, the admin-tier audience the final student-change stage already routes to. */
    protected function notifyStudentAdminHolders(School $school, string $title, string $body): void
    {
        $roleIds = Role::where('school_id', $school->id)
            ->whereHas('permissions', fn ($q) => $q->where('code', 'students.manage_settings'))
            ->pluck('id');

        $userIds = DB::table('school_user')
            ->where('school_id', $school->id)->where('status', 'active')
            ->whereIn('role_id', $roleIds)->pluck('user_id');

        foreach ($userIds->unique() as $userId) {
            Notification::create([
                'user_id' => $userId, 'title' => $title, 'body' => $body,
                'category' => 'student_profile_change_pending_admin', 'type' => Notification::typeFor('student_profile_change_pending_admin'),
            ]);
        }
    }

    protected function nudgeOne(Request $request, string $key): bool
    {
        $school = $this->school($request);
        [$type, $id] = array_pad(explode(':', $key, 2), 2, null);

        switch ($type) {
            case 'profile_change':
                if (! ProfileChangeRequest::where('status', ProfileChangeRequest::STATUS_PENDING)->whereKey($id)->exists()) {
                    return false;
                }
                $this->notifyStaffUpdateHolders($school, 'Reminder: a profile change is waiting on you', 'A profile change request is still awaiting your review under Users > Profile approvals.');

                return true;

            case 'password_reset':
                if (! PasswordResetRequest::where('status', PasswordResetRequest::STATUS_PENDING)->whereKey($id)->exists()) {
                    return false;
                }
                $this->notifyStaffUpdateHolders($school, 'Reminder: a password reset is waiting on you', 'A password reset request is still awaiting your review under Users > Profile approvals.');

                return true;

            case 'student_change_teacher':
                $r = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_CLASS_TEACHER)->with('student')->find($id);
                if (! $r) {
                    return false;
                }
                $staff = Staff::find($r->class_teacher_staff_id);
                if (! $staff?->user_id) {
                    return false;
                }
                Notification::create([
                    'user_id' => $staff->user_id, 'title' => 'Reminder: a student profile change is waiting on you',
                    'body' => "{$r->student?->fullName()}'s profile change is still awaiting your review under Students > Change Requests.",
                    'category' => 'student_profile_change_pending_class_teacher',
                    'type' => Notification::typeFor('student_profile_change_pending_class_teacher'),
                ]);

                return true;

            case 'student_change_admin':
                $r = StudentProfileChangeRequest::where('status', StudentProfileChangeRequest::STATUS_PENDING_ADMIN)->with('student')->find($id);
                if (! $r) {
                    return false;
                }
                $this->notifyStudentAdminHolders($school, 'Reminder: a student profile change is waiting on you', "{$r->student?->fullName()}'s profile change is still awaiting final approval under Student Change Requests.");

                return true;

            case 'report_card_review':
                $r = ReportCardReview::whereIn('status', [ReportCardReview::STATUS_PENDING_REVIEW, ReportCardReview::STATUS_NEEDS_CORRECTION])->with('student', 'assignedTo')->find($id);
                if (! $r || ! $r->assignedTo?->user_id) {
                    return false;
                }
                Notification::create([
                    'user_id' => $r->assignedTo->user_id, 'title' => 'Report card awaiting your review',
                    'body' => "{$r->student?->fullName()}'s report card is still waiting on you to review, approve, or send back.",
                    'category' => 'report_card_review', 'type' => Notification::typeFor('report_card_review'),
                ]);

                return true;

            default:
                return false;
        }
    }
}
