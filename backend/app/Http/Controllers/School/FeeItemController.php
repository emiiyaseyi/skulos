<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\BankAccount;
use App\Models\Tenant\FeeItem;
use App\Models\Tenant\FeeItemInstallment;
use App\Models\Tenant\Notification;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Academics\AcademicDefaults;
use App\Services\Bulk\CsvReader;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Basic Fee Records (Foundation Finance slice, 2026-07-17): a school
 * sets up its own fee catalog per term ("Tuition", "PTA Levy", an
 * amount), the thing a bank transfer Payment then gets matched against.
 * No per-section/per-class scoping in this minimal slice.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class FeeItemController extends Controller
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
        $this->authorizeAction($request, 'finance.view');
        $school = $this->school($request);
        $user = Auth::user();

        $feeItems = FeeItem::with(['term', 'schoolClasses'])->orderByDesc('created_at')->get();
        $schoolClasses = SchoolClass::active()->with('section')->orderBy('sequence')->get()
            ->sortBy(fn (SchoolClass $c) => sprintf('%05d-%05d', $c->section?->sequence ?? 0, $c->sequence ?? 0))->values();
        $chain = $this->feeApprovalChain();

        return view('school.finance.fee-items.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'feeItems' => $feeItems,
            'terms' => Term::with('session')->get()->sortBy(fn (Term $t) => sprintf('%05d-%05d', $t->session?->id ?? 0, $t->sequence ?? 0))->values(),
            'upcomingTerm' => $this->upcomingTermOption(),
            'schoolClasses' => $schoolClasses,
            'feeSchedule' => $this->feeSchedule($feeItems->where('approval_status', FeeItem::APPROVAL_APPROVED), $schoolClasses),
            'bankAccounts' => BankAccount::orderBy('sort_order')->get(),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'finance.update'),
            'feeApprovalEnabled' => $this->feeApprovalEnabled(),
            'feeApprovalChain' => $chain,
            'feeApprovalChainLabels' => $this->describeApprovalChain($school, $chain),
            'feeApprovalRoles' => config('skulos.fee_approval_roles'),
            'staffForApproval' => Staff::whereNotNull('user_id')->orderBy('first_name')->get(['id', 'first_name', 'last_name', 'user_id']),
            'isEligibleApprover' => $this->isEligibleApprover($user, $school, $chain),
        ]);
    }

    protected function feeApprovalEnabled(): bool
    {
        return (bool) Setting::grab('fee_approval_enabled', false);
    }

    /** @return list<string> each entry "role:{slug}" or "staff:{staffId}" */
    protected function feeApprovalChain(): array
    {
        return Setting::grab('fee_approval_chain', []);
    }

    /** Human readable labels for the configured chain, for display on the settings card. */
    protected function describeApprovalChain(School $school, array $chain): array
    {
        $roleLabels = config('skulos.fee_approval_roles');
        $staffNames = Staff::whereIn('id', collect($chain)
            ->filter(fn ($e) => str_starts_with($e, 'staff:'))
            ->map(fn ($e) => (int) substr($e, 6)))
            ->get(['id', 'first_name', 'last_name'])->keyBy('id');

        return collect($chain)->map(function ($entry) use ($roleLabels, $staffNames) {
            if (str_starts_with($entry, 'role:')) {
                $slug = substr($entry, 5);

                return $roleLabels[$slug] ?? $slug;
            }
            $staffId = (int) substr($entry, 6);

            return $staffNames->get($staffId)?->fullName() ?? 'Removed staff member';
        })->all();
    }

    /** Whether $user can approve/reject a pending fee item: holds one of the chain's roles, or is specifically named in it. */
    protected function isEligibleApprover(User $user, School $school, array $chain): bool
    {
        if (empty($chain)) {
            return false;
        }

        $staff = Staff::where('user_id', $user->id)->first();
        foreach ($chain as $entry) {
            if (str_starts_with($entry, 'staff:') && $staff && (int) substr($entry, 6) === $staff->id) {
                return true;
            }
            if (str_starts_with($entry, 'role:')) {
                $slug = substr($entry, 5);
                if (optional($user->roleIn($school))->slug === $slug) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * "The first section should show the schools fees in a tabular form
     * for all classes... only shows if the school admin has uploaded,
     * set or entered the fee items" (user request 2026-07-25): one table
     * per term, fee item names as rows, classes as columns, so the whole
     * fee schedule reads at a glance instead of one row per fee item per
     * class. A blank cell means that fee item does not apply to that
     * class at all.
     *
     * @return list<array{term: string, classes: \Illuminate\Support\Collection, rows: list<array{name: string, amounts: array<int, ?string>}>}>
     */
    protected function feeSchedule(\Illuminate\Support\Collection $feeItems, \Illuminate\Support\Collection $schoolClasses): array
    {
        $schedule = [];

        foreach ($feeItems->groupBy('term_id') as $itemsForTerm) {
            $termName = $itemsForTerm->first()->term?->name ?? 'No term';
            $rows = [];

            foreach ($itemsForTerm->pluck('name')->unique() as $name) {
                $itemsNamed = $itemsForTerm->where('name', $name);
                $amounts = [];
                foreach ($schoolClasses as $class) {
                    $match = $itemsNamed->first(fn (FeeItem $i) => $i->appliesToClass($class->id));
                    $amounts[$class->id] = $match ? number_format((float) $match->amount, 2) : null;
                }
                $rows[] = ['name' => $name, 'amounts' => $amounts];
            }

            $schedule[] = ['term' => $termName, 'classes' => $schoolClasses, 'rows' => $rows];
        }

        return $schedule;
    }

    /**
     * "Under Term it should also show the new coming session if the
     * school is in Third term currently... this is automated based on
     * the current term if third term and session year" (user request
     * 2026-07-25). Only ever a suggestion, computed fresh on every page
     * load, nothing is created just from looking at this screen — the
     * real AcademicSession/Term rows only get created if an admin
     * actually picks it and saves a fee item against it (see store()).
     * Returns null once the school (or the normal End of Term rollover)
     * has already created that session for real, since by then it is
     * just a normal option in $terms already.
     */
    protected function upcomingTermOption(): ?array
    {
        $currentTerm = Term::current()->with('session')->first();
        if (! $currentTerm) {
            return null;
        }

        $isLastTermOfSession = ! Term::where('academic_session_id', $currentTerm->academic_session_id)
            ->where('sequence', '>', $currentTerm->sequence)
            ->exists();
        if (! $isLastTermOfSession) {
            return null;
        }

        $nextStartYear = app(AcademicDefaults::class)->nextStartYearAfter($currentTerm->session);
        $yearLabel = $nextStartYear.'/'.($nextStartYear + 1);
        if (AcademicSession::where('name', "{$yearLabel} Session")->exists()) {
            return null;
        }

        return ['value' => "upcoming:{$nextStartYear}", 'label' => "First Term {$yearLabel} (not created yet)"];
    }

    /**
     * Resolves a term_id form value that may be the upcomingTermOption()
     * sentinel ("upcoming:{year}") into a real Term id, creating that
     * session and its terms first if nothing beat this request to it
     * (AcademicDefaults::createSessionWithTerms() is idempotent, the same
     * "already exists" guard the End of Term rollover itself relies on).
     */
    protected function resolveTermId(string $termId): int
    {
        if (! str_starts_with($termId, 'upcoming:')) {
            return (int) $termId;
        }

        $startYear = (int) substr($termId, strlen('upcoming:'));
        $yearLabel = $startYear.'/'.($startYear + 1);
        $session = app(AcademicDefaults::class)->createSessionWithTerms($startYear)
            ?? AcademicSession::where('name', "{$yearLabel} Session")->firstOrFail();

        return Term::where('academic_session_id', $session->id)->orderBy('sequence')->firstOrFail()->id;
    }

    /**
     * Gated on finance.update, not finance.create (audit finding
     * 2026-07-18, user check-in "hope admin can set this and parent can
     * see it too" surfaced it live): finance.create is what a parent
     * legitimately holds to submit a payment (PaymentController::store()),
     * a completely different capability from defining the school's own
     * fee catalog, which is institution-level policy, admin only, the
     * same tier every other write action on this controller already
     * requires.
     */
    /** term_id is either a real term id or the upcomingTermOption() "upcoming:{year}" sentinel, resolveTermId() below turns either into a real id. */
    protected function termIdRule(): \Closure
    {
        return function (string $attribute, mixed $value, \Closure $fail) {
            if (str_starts_with((string) $value, 'upcoming:')) {
                return;
            }
            if (! Term::whereKey($value)->exists()) {
                $fail('The selected term is invalid.');
            }
        };
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'term_id' => ['required', $this->termIdRule()],
            'allow_installments' => ['nullable', 'boolean'],
            'class_ids' => ['nullable', 'array'],
            'class_ids.*' => ['exists:tenant.school_classes,id'],
        ]);
        $data['allow_installments'] = (bool) ($data['allow_installments'] ?? false);
        $data['term_id'] = $this->resolveTermId($data['term_id']);
        $classIds = $data['class_ids'] ?? [];
        unset($data['class_ids']);

        $feeItem = FeeItem::create($data);
        $feeItem->schoolClasses()->sync($classIds);
        $this->maybeRequireApproval($this->school($request), $feeItem);

        return back()->with('status', $feeItem->fresh()->approval_status === FeeItem::APPROVAL_PENDING
            ? "\"{$data['name']}\" added and is awaiting approval before parents can see it."
            : "\"{$data['name']}\" added to the fee catalog.");
    }

    /**
     * A new fee item starts 'pending' instead of the migration's own
     * 'approved' default only when the school has actually turned fee
     * approval on and configured at least one approver (user request
     * 2026-07-25), an explicit query-builder update since approval_status
     * is deliberately not fillable (see FeeItem's own docblock). Notifies
     * every eligible approver the same way payment submission already
     * notifies finance staff.
     */
    protected function maybeRequireApproval(School $school, FeeItem $feeItem): void
    {
        $chain = $this->feeApprovalChain();
        if (! $this->feeApprovalEnabled() || empty($chain)) {
            return;
        }

        FeeItem::whereKey($feeItem->id)->update(['approval_status' => FeeItem::APPROVAL_PENDING]);

        $roleSlugs = collect($chain)->filter(fn ($e) => str_starts_with($e, 'role:'))->map(fn ($e) => substr($e, 5));
        $staffIds = collect($chain)->filter(fn ($e) => str_starts_with($e, 'staff:'))->map(fn ($e) => (int) substr($e, 6));

        $userIds = collect();
        if ($roleSlugs->isNotEmpty()) {
            $roleIds = \App\Models\Role::where('school_id', $school->id)->whereIn('slug', $roleSlugs)->pluck('id');
            $userIds = $userIds->merge(\Illuminate\Support\Facades\DB::table('school_user')
                ->where('school_id', $school->id)
                ->where('status', 'active')->whereIn('role_id', $roleIds)->pluck('user_id'));
        }
        if ($staffIds->isNotEmpty()) {
            $userIds = $userIds->merge(Staff::whereIn('id', $staffIds)->pluck('user_id')->filter());
        }

        foreach ($userIds->unique() as $userId) {
            Notification::create([
                'user_id' => $userId,
                'category' => 'fee_item_pending_approval',
                'type' => Notification::typeFor('fee_item_pending_approval'),
                'title' => 'Fee item awaiting approval',
                'body' => "\"{$feeItem->name}\" (₦".number_format((float) $feeItem->amount, 2).") needs your approval before parents can see it.",
            ]);
        }
    }

    /** Which roles/staff can approve a fee item before parents see it (user request 2026-07-25). */
    public function updateApprovalSettings(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');

        $data = $request->validate([
            'enabled' => ['required', 'boolean'],
            'chain' => ['nullable', 'array'],
            'chain.*' => ['nullable', 'string'],
        ]);

        $roleSlugs = array_keys(config('skulos.fee_approval_roles'));
        $chain = collect($data['chain'] ?? [])->filter()->filter(function ($entry) use ($roleSlugs) {
            if (str_starts_with($entry, 'role:')) {
                return in_array(substr($entry, 5), $roleSlugs, true);
            }

            return str_starts_with($entry, 'staff:') && Staff::whereKey((int) substr($entry, 6))->exists();
        })->values()->all();

        Setting::put('fee_approval_enabled', $data['enabled']);
        Setting::put('fee_approval_chain', $chain);

        return back()->with('status', $data['enabled']
            ? 'Fee approval is on. A new fee item now needs approval before parents can see it.'
            : 'Fee approval is off. A new fee item is visible to parents as soon as it is added.');
    }

    public function approveFeeItem(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decideFeeItem($request, FeeItem::APPROVAL_APPROVED);
    }

    public function rejectFeeItem(Request $request): \Illuminate\Http\RedirectResponse
    {
        return $this->decideFeeItem($request, FeeItem::APPROVAL_REJECTED);
    }

    protected function decideFeeItem(Request $request, string $status): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $user = Auth::user();
        abort_unless($this->isEligibleApprover($user, $school, $this->feeApprovalChain()), 403, 'You are not on the fee approval list.');

        $feeItem = FeeItem::findOrFail($request->route('feeItemId'));

        // Only a still-pending row can transition, the same atomic
        // "only the request whose UPDATE actually changed a row
        // proceeds" guard PaymentController::applyDecision() already
        // uses, closing off the same two-approvers-race window.
        $updated = FeeItem::where('id', $feeItem->id)
            ->where('approval_status', FeeItem::APPROVAL_PENDING)
            ->update(['approval_status' => $status, 'approved_by_user_id' => $user->id, 'approved_at' => now()]);

        if ($updated === 0) {
            return back()->with('error', 'This fee item has already been decided.');
        }

        $verb = $status === FeeItem::APPROVAL_APPROVED ? 'approved' : 'rejected';

        return back()->with('status', "\"{$feeItem->name}\" {$verb}.");
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $feeItem = FeeItem::findOrFail($request->route('feeItemId'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'term_id' => ['required', $this->termIdRule()],
            'allow_installments' => ['nullable', 'boolean'],
            'class_ids' => ['nullable', 'array'],
            'class_ids.*' => ['exists:tenant.school_classes,id'],
        ]);
        $data['allow_installments'] = (bool) ($data['allow_installments'] ?? false);
        $data['term_id'] = $this->resolveTermId($data['term_id']);
        $classIds = $data['class_ids'] ?? [];
        unset($data['class_ids']);

        $feeItem->update($data);
        $feeItem->schoolClasses()->sync($classIds);

        return back()->with('status', "\"{$feeItem->name}\" updated.");
    }

    /** Retire (hide from new payments) or reactivate a fee item without deleting it, so already-submitted payments keep their reference. */
    public function toggleActive(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $feeItem = FeeItem::findOrFail($request->route('feeItemId'));
        $feeItem->update(['is_active' => ! $feeItem->is_active]);

        return back()->with('status', $feeItem->is_active
            ? "\"{$feeItem->name}\" is active again."
            : "\"{$feeItem->name}\" is retired. Already-submitted payments against it are unaffected.");
    }

    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.delete');
        $feeItem = FeeItem::findOrFail($request->route('feeItemId'));

        if ($feeItem->payments()->exists()) {
            return back()->with('error', "\"{$feeItem->name}\" has payments recorded against it and cannot be deleted. Retire it instead.");
        }

        $name = $feeItem->name;
        $feeItem->delete();

        return back()->with('status', "\"{$name}\" removed from the fee catalog.");
    }

    /**
     * A fee item's own page (user-facing "Installment Payment Plans",
     * 2026-07-18): edit the fee item itself (including whether it allows
     * installments), and manage its installment schedule, with each
     * installment's collection progress against already-verified
     * payments.
     */
    public function show(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'finance.view');
        $school = $this->school($request);
        $feeItem = FeeItem::with(['term', 'installments', 'schoolClasses'])->findOrFail($request->route('feeItemId'));

        return view('school.finance.fee-items.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'feeItem' => $feeItem,
            'terms' => Term::with('session')->get()->sortBy(fn (Term $t) => sprintf('%05d-%05d', $t->session?->id ?? 0, $t->sequence ?? 0))->values(),
            'schoolClasses' => SchoolClass::active()->with('section')->orderBy('sequence')->get()
                ->sortBy(fn (SchoolClass $c) => sprintf('%05d-%05d', $c->section?->sequence ?? 0, $c->sequence ?? 0))->values(),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'finance.update'),
        ]);
    }

    public function storeInstallment(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $feeItem = FeeItem::findOrFail($request->route('feeItemId'));

        $data = $request->validate([
            'label' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0.01'],
            'due_date' => ['nullable', 'date'],
        ]);

        $feeItem->installments()->create([
            'label' => $data['label'],
            'amount' => $data['amount'],
            'due_date' => $data['due_date'] ?? null,
            'sequence' => (int) $feeItem->installments()->max('sequence') + 1,
        ]);

        return back()->with('status', "\"{$data['label']}\" added to {$feeItem->name}'s installment schedule.");
    }

    public function destroyInstallment(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $installment = FeeItemInstallment::findOrFail($request->route('installmentId'));

        if ($installment->payments()->exists()) {
            return back()->with('error', "\"{$installment->label}\" has payments recorded against it and cannot be removed.");
        }

        $installment->delete();

        return back()->with('status', 'Installment removed.');
    }

    /** Bulk delete for ticked installments, matching the standing bulk-actions convention every list screen follows. */
    public function bulkActionInstallments(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $data = $request->validate([
            'installment_ids' => ['required', 'array', 'min:1'],
            'installment_ids.*' => ['exists:tenant.fee_item_installments,id'],
        ]);

        $items = FeeItemInstallment::whereIn('id', $data['installment_ids'])->withCount('payments')->get();
        $deletable = $items->where('payments_count', 0);
        $skipped = $items->count() - $deletable->count();
        FeeItemInstallment::whereIn('id', $deletable->pluck('id'))->delete();

        $message = "{$deletable->count()} installment(s) removed.";
        if ($skipped > 0) {
            $message .= " {$skipped} skipped (has payments recorded against it).";
        }

        return back()->with('status', $message);
    }

    /** Bulk retire/reactivate/delete for ticked fee items, matching the standing bulk-actions convention every list screen follows (audit finding M-12, 2026-07-17). */
    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'fee_item_ids' => ['required', 'array', 'min:1'],
            'fee_item_ids.*' => ['exists:tenant.fee_items,id'],
            'action' => ['required', 'in:activate,retire,delete'],
        ]);

        if ($data['action'] === 'delete') {
            $this->authorizeAction($request, 'finance.delete');
            $items = FeeItem::whereIn('id', $data['fee_item_ids'])->withCount('payments')->get();
            $deletable = $items->where('payments_count', 0);
            $skipped = $items->count() - $deletable->count();
            FeeItem::whereIn('id', $deletable->pluck('id'))->delete();

            $message = "{$deletable->count()} fee item(s) removed.";
            if ($skipped > 0) {
                $message .= " {$skipped} skipped (has payments recorded against it).";
            }

            return back()->with('status', $message);
        }

        $this->authorizeAction($request, 'finance.update');
        $count = FeeItem::whereIn('id', $data['fee_item_ids'])->update(['is_active' => $data['action'] === 'activate']);

        return back()->with('status', "{$count} fee item(s) updated.");
    }

    /**
     * A downloadable CSV template (user request 2026-07-25: "make the fee
     * items that one can upload as a bulk file, add a downloadable csv
     * too"), one example row so the expected shape is obvious rather than
     * a bare header line. "classes" is semicolon separated class names,
     * left blank to mean every class, matching bulkUpload() below.
     */
    public function template(Request $request): \Illuminate\Http\Response
    {
        $this->authorizeAction($request, 'finance.update');
        $school = $this->school($request);

        $currentTerm = Term::current()->first();

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['name', 'amount', 'term', 'allow_installments', 'classes']);
        fputcsv($out, ['Tuition', '50000', $currentTerm?->name ?? 'First Term 2025/2026', 'no', '']);
        fputcsv($out, ['Sports Wear', '5000', $currentTerm?->name ?? 'First Term 2025/2026', 'no', 'Nursery;Primary 1']);
        rewind($out);
        $csv = (string) stream_get_contents($out);

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$school->slug.'-fee-items-template.csv"',
        ]);
    }

    /**
     * Re-upload of the template above, the bulk way to set up a school's
     * whole fee schedule at once (user request 2026-07-25: "alternatively,
     * the school can decide to upload a file that will act as the school
     * fees"). Additive, like every other bulk import in this app, a
     * mistaken row is fixed by retiring/deleting that one fee item
     * afterwards rather than this action being destructive to whatever
     * already exists. Term and class names are matched case
     * insensitively against this school's own real records; an unknown
     * term fails that row rather than guessing, an unknown class name is
     * dropped from that row's list with a warning rather than failing
     * the whole row over one typo.
     */
    public function bulkUpload(Request $request, CsvReader $reader): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:5120']]);

        $csv = file_get_contents($request->file('file')->getRealPath());
        try {
            $rows = $reader->rows($csv);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $termsByName = Term::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => $id])->all();
        $classesByName = SchoolClass::pluck('id', 'name')->mapWithKeys(fn ($id, $name) => [mb_strtolower(trim($name)) => $id])->all();

        $created = 0;
        $errors = [];

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $name = trim((string) ($row['name'] ?? ''));
            $amount = trim((string) ($row['amount'] ?? ''));
            $termName = trim((string) ($row['term'] ?? ''));

            if ($name === '' || ! is_numeric($amount)) {
                $errors[] = "Row {$rowNumber}: name and a numeric amount are required.";

                continue;
            }

            $termId = $termsByName[mb_strtolower($termName)] ?? null;
            if (! $termId) {
                $errors[] = "Row {$rowNumber}: term \"{$termName}\" was not found.";

                continue;
            }

            $classNames = array_filter(array_map('trim', explode(';', (string) ($row['classes'] ?? ''))));
            $classIds = [];
            foreach ($classNames as $className) {
                $classId = $classesByName[mb_strtolower($className)] ?? null;
                if ($classId) {
                    $classIds[] = $classId;
                } else {
                    $errors[] = "Row {$rowNumber}: class \"{$className}\" was not found, skipped for this fee item.";
                }
            }

            $allowInstallments = in_array(mb_strtolower(trim((string) ($row['allow_installments'] ?? ''))), ['yes', 'y', 'true', '1'], true);

            $feeItem = FeeItem::create([
                'name' => $name, 'amount' => $amount, 'term_id' => $termId, 'allow_installments' => $allowInstallments,
            ]);
            $feeItem->schoolClasses()->sync($classIds);
            $created++;
        }

        $message = "{$created} fee item(s) added.";
        if ($errors) {
            $message .= ' '.count($errors).' issue(s): '.implode(' ', array_slice($errors, 0, 5)).(count($errors) > 5 ? ' ...' : '');
        }

        return back()->with($errors && $created === 0 ? 'error' : 'status', $message);
    }
}
