<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\FeeItem;
use App\Models\Tenant\FeeItemInstallment;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

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

        return view('school.finance.fee-items.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'feeItems' => FeeItem::with('term')->orderByDesc('created_at')->get(),
            'terms' => Term::with('session')->get()->sortBy(fn (Term $t) => sprintf('%05d-%05d', $t->session?->id ?? 0, $t->sequence ?? 0))->values(),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'finance.create'),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.create');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'term_id' => ['required', 'exists:tenant.terms,id'],
            'allow_installments' => ['nullable', 'boolean'],
        ]);
        $data['allow_installments'] = (bool) ($data['allow_installments'] ?? false);

        FeeItem::create($data);

        return back()->with('status', "\"{$data['name']}\" added to the fee catalog.");
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $feeItem = FeeItem::findOrFail($request->route('feeItemId'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'amount' => ['required', 'numeric', 'min:0'],
            'term_id' => ['required', 'exists:tenant.terms,id'],
            'allow_installments' => ['nullable', 'boolean'],
        ]);
        $data['allow_installments'] = (bool) ($data['allow_installments'] ?? false);

        $feeItem->update($data);

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
        $feeItem = FeeItem::with(['term', 'installments'])->findOrFail($request->route('feeItemId'));

        return view('school.finance.fee-items.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'feeItem' => $feeItem,
            'terms' => Term::with('session')->get()->sortBy(fn (Term $t) => sprintf('%05d-%05d', $t->session?->id ?? 0, $t->sequence ?? 0))->values(),
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
}
