<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\House;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Staff;
use App\Services\Accounts\AccountProvisioningService;
use App\Services\CustomFields\CustomFieldService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Staff list, profile, single create/edit, and login provisioning (Staff
 * and Parent Portal, user request 2026-07-12: "Staff module... no admin
 * screens yet at all", per CLAUDE.md's confirmed decisions section — this
 * is that screen). Bulk CSV import and a configurable-required-fields
 * settings page (the Student module's equivalent) are deliberately not
 * built in this pass, see the tracker Build Log entry for why.
 */
class StaffController extends Controller
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

    /** Column a plain "Sort by" dropdown may request, matched to the real underlying column(s). */
    protected function staffSortColumn(string $sort): array
    {
        return match ($sort) {
            'staff_id' => ['staff_id'],
            'position' => ['position'],
            'status' => ['status'],
            default => ['last_name', 'first_name'],
        };
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'staff.view');
        $school = $this->school($request);
        $search = trim((string) $request->query('q'));
        // Sort and filter (user report 2026-07-21: "Staff and parent list
        // does not have the sort and filter option"), the same ?sort=/
        // ?dir= shape the class broadsheet already established.
        $status = in_array($request->query('status'), Staff::STATUSES, true) ? $request->query('status') : '';
        $sort = in_array($request->query('sort'), ['name', 'staff_id', 'position', 'status'], true) ? $request->query('sort') : 'name';
        $dir = $request->query('dir') === 'desc' ? 'desc' : 'asc';

        $staff = Staff::query()
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('staff_id', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%")
                    ->orWhere('position', 'like', "%{$search}%");
            }))
            ->when($status, fn ($q) => $q->where('status', $status))
            ->tap(function ($q) use ($sort, $dir) {
                foreach ($this->staffSortColumn($sort) as $column) {
                    $q->orderBy($column, $dir);
                }
            })
            ->paginate(20)
            ->withQueryString();

        return view('school.staff.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'staff' => $staff,
            'search' => $search,
            'status' => $status,
            'sort' => $sort,
            'dir' => $dir,
            'statusOptions' => Staff::STATUSES,
            'total' => Staff::count(),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'staff.update'),
            'canCreate' => app(Rbac::class)->check(Auth::user(), $school, 'staff.create'),
        ]);
    }

    /** Bulk status change or delete for ticked staff, the same pattern every list screen in the product uses. */
    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'staff.update');
        $data = $request->validate([
            'staff_ids' => ['required', 'array', 'min:1'],
            'staff_ids.*' => ['exists:tenant.staff,id'],
            'action' => ['required', 'in:active,on_leave,suspended,resigned,delete'],
        ]);

        $query = Staff::whereIn('id', $data['staff_ids']);

        if ($data['action'] === 'delete') {
            $this->authorizeAction($request, 'staff.delete');
            $count = $query->delete();

            return back()->with('status', "{$count} staff member(s) removed.");
        }

        $count = $query->update(['status' => $data['action']]);

        return back()->with('status', "{$count} staff member(s) updated.");
    }

    /** Soft-deleted staff (user request: bulk restore/undo, 2026-07-12), so a bulk delete is never a one-way door. */
    public function trashed(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'staff.view');
        $school = $this->school($request);
        $search = trim((string) $request->query('q'));

        $staff = Staff::onlyTrashed()
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('staff_id', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->orderBy('deleted_at', 'desc')
            ->paginate(20)
            ->withQueryString();

        return view('school.staff.trashed', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'staff' => $staff,
            'search' => $search,
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'staff.delete'),
        ]);
    }

    /**
     * Restore or permanently delete ticked trashed staff. Permanently
     * deleting clears any dangling references (class teacher, assistant
     * teacher, housemaster, default report card reviewer) since those
     * columns are plain ids with no foreign key, they would otherwise
     * keep pointing at a row that no longer exists. A staff member with
     * an active login is skipped, since force deleting them would orphan
     * a live account with no staff profile behind it; the admin is told
     * to revoke that login first.
     */
    public function trashedBulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'staff.delete');
        $data = $request->validate([
            'staff_ids' => ['required', 'array', 'min:1'],
            'staff_ids.*' => ['integer'],
            'action' => ['required', 'in:restore,force-delete'],
        ]);

        $query = Staff::onlyTrashed()->whereIn('id', $data['staff_ids']);

        if ($data['action'] === 'restore') {
            $count = $query->restore();

            return back()->with('status', "{$count} staff member(s) restored.");
        }

        $staffMembers = $query->get();
        $withLogin = $staffMembers->filter(fn ($s) => $s->user_id !== null);
        $toDelete = $staffMembers->reject(fn ($s) => $s->user_id !== null);

        foreach ($toDelete as $staffMember) {
            ClassArm::where('class_teacher_staff_id', $staffMember->id)->update(['class_teacher_staff_id' => null]);
            ClassArm::where('assistant_teacher_staff_id', $staffMember->id)->update(['assistant_teacher_staff_id' => null]);
            House::where('housemaster_staff_id', $staffMember->id)->update(['housemaster_staff_id' => null]);
            House::where('assistant_housemaster_staff_id', $staffMember->id)->update(['assistant_housemaster_staff_id' => null]);
            SchoolClass::where('default_reviewer_staff_id', $staffMember->id)->update(['default_reviewer_staff_id' => null]);
            $staffMember->forceDelete();
        }

        $message = "{$toDelete->count()} staff member(s) permanently deleted. This cannot be undone.";
        if ($withLogin->isNotEmpty()) {
            $message .= " {$withLogin->count()} skipped because they still have an active login, revoke it first.";
        }

        return back()->with('status', $message);
    }

    public function show(Request $request, CustomFieldService $customFields): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'staff.view');
        $school = $this->school($request);
        $staffMember = Staff::with(['teachingAssignments.subject', 'teachingAssignments.classArm.schoolClass'])
            ->findOrFail($request->route('staffId'));

        $classTeacherOf = ClassArm::with('schoolClass')->where('class_teacher_staff_id', $staffMember->id)->get();
        $assistantOf = ClassArm::with('schoolClass')->where('assistant_teacher_staff_id', $staffMember->id)->get();

        $login = $staffMember->user_id
            ? $school->users()->whereKey($staffMember->user_id)->first()
            : null;

        $rbac = app(Rbac::class);

        return view('school.staff.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'staffMember' => $staffMember,
            'classTeacherOf' => $classTeacherOf,
            'assistantOf' => $assistantOf,
            'login' => $login,
            'roles' => $this->assignableRoles(),
            'staffIdentifier' => Setting::grab('staff_identifier', 'email'),
            'canManage' => $rbac->check(Auth::user(), $school, 'staff.update'),
            'staffCustomFields' => $customFields->forEntity('staff'),
            'staffCustomValues' => $customFields->valuesFor('staff', $staffMember->id),
        ]);
    }

    public function create(Request $request, CustomFieldService $customFields): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'staff.create');
        $school = $this->school($request);

        return view('school.staff.form', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'staffMember' => new Staff(),
            'staffIdentifier' => Setting::grab('staff_identifier', 'email'),
            'customFields' => $customFields->forEntity('staff'),
            'customValues' => [],
        ]);
    }

    public function store(Request $request, CustomFieldService $customFields): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'staff.create');
        $school = $this->school($request);
        $data = $request->validate(array_merge($this->staffValidationRules(), $customFields->validationRules('staff')));
        $custom = Arr::pull($data, 'custom', []);

        $nextSeq = Staff::withTrashed()->count() + 1;
        $data['staff_id'] = sprintf('STF/%04d', $nextSeq);

        $staffMember = Staff::create($data);
        $customFields->saveValues('staff', $staffMember->id, $custom);

        return redirect()->route('school.staff.show', ['school' => $school->slug, 'staffId' => $staffMember->id])
            ->with('status', "{$staffMember->fullName()} added.");
    }

    public function edit(Request $request, CustomFieldService $customFields): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'staff.update');
        $school = $this->school($request);
        $staffMember = Staff::findOrFail($request->route('staffId'));

        return view('school.staff.form', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'staffMember' => $staffMember,
            'staffIdentifier' => Setting::grab('staff_identifier', 'email'),
            'customFields' => $customFields->forEntity('staff'),
            'customValues' => $customFields->valuesFor('staff', $staffMember->id),
        ]);
    }

    public function update(Request $request, CustomFieldService $customFields): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'staff.update');
        $school = $this->school($request);
        $staffMember = Staff::findOrFail($request->route('staffId'));
        $data = $request->validate(array_merge($this->staffValidationRules($staffMember), $customFields->validationRules('staff')));
        $custom = Arr::pull($data, 'custom', []);

        $staffMember->update($data);
        $customFields->saveValues('staff', $staffMember->id, $custom);

        return redirect()->route('school.staff.show', ['school' => $school->slug, 'staffId' => $staffMember->id])
            ->with('status', "{$staffMember->fullName()} updated.");
    }

    /** Every role except the platform-only ones (assigned via config, never hardcoded here). */
    protected function assignableRoles(): array
    {
        return collect(config('skulos.roles'))
            ->except(['school-owner'])
            ->map(fn ($def, $slug) => ['slug' => $slug, 'name' => $def['name']])
            ->values()
            ->all();
    }

    /** Creates this staff member's login (Staff Portal, user request 2026-07-12). Requires an email regardless of staff_identifier, since sign in is always by email today. */
    public function createLogin(Request $request, AccountProvisioningService $accounts): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'staff.update');
        $school = $this->school($request);
        $staffMember = Staff::findOrFail($request->route('staffId'));

        $data = $request->validate([
            'email' => ['required', 'email', 'max:150'],
            'role' => ['required', 'string', Rule::in(array_column($this->assignableRoles(), 'slug'))],
        ]);

        $result = $accounts->provision($school, $staffMember->fullName(), $data['email'], $data['role']);
        $staffMember->update(['user_id' => $result['user']->id, 'email' => $staffMember->email ?: $data['email']]);

        return back()->with('status', "{$staffMember->fullName()}'s login has been created and emailed to them.");
    }

    public function resetLoginPassword(Request $request, AccountProvisioningService $accounts): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'staff.update');
        $school = $this->school($request);
        $staffMember = Staff::findOrFail($request->route('staffId'));
        abort_unless($staffMember->user_id, 404);

        $accounts->resetPassword($school, \App\Models\User::findOrFail($staffMember->user_id));

        return back()->with('status', "{$staffMember->fullName()}'s password has been reset and emailed to them.");
    }

    public function toggleLoginAccess(Request $request, AccountProvisioningService $accounts): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'staff.update');
        $school = $this->school($request);
        $staffMember = Staff::findOrFail($request->route('staffId'));
        abort_unless($staffMember->user_id, 404);

        $user = \App\Models\User::findOrFail($staffMember->user_id);
        $current = $school->users()->whereKey($user->id)->first()->pivot->status;

        if ($current === 'active') {
            $accounts->revokeAccess($school, $user);
            $status = 'revoked';
        } else {
            $accounts->restoreAccess($school, $user);
            $status = 'restored';
        }

        return back()->with('status', "{$staffMember->fullName()}'s login access has been {$status}.");
    }

    /**
     * Which of email/phone is required depends on the school's own choice
     * (tenant Setting "staff_identifier", same enforcement
     * StudentController::storeGuardian() already applies for
     * "parent_identifier" — this was stored and admin editable since
     * 2026-07-11 but never actually enforced anywhere until the Staff
     * module itself existed, see CLAUDE.md's confirmed decisions section).
     */
    protected function staffValidationRules(?Staff $staffMember = null): array
    {
        $identifier = Setting::grab('staff_identifier', 'email');

        return [
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:20'],
            'email' => [$identifier === 'phone' ? 'nullable' : ($identifier === 'both' ? 'required_without:phone' : 'required'), 'email', 'max:150'],
            'phone' => [$identifier === 'email' ? 'nullable' : ($identifier === 'both' ? 'required_without:email' : 'required'), 'string', 'max:40'],
            'position' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date'],
            'employed_on' => ['nullable', 'date'],
            'qualification' => ['nullable', 'string', 'max:255'],
            'address' => ['nullable', 'string', 'max:500'],
            'status' => ['required', Rule::in([Staff::STATUS_ACTIVE, Staff::STATUS_ON_LEAVE, Staff::STATUS_SUSPENDED, Staff::STATUS_RESIGNED])],
        ];
    }
}
