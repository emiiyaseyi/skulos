<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\BankAccount;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A school's own receiving bank account(s) for the Bank Transfer payment
 * option (user request 2026-07-25: "School admin sets the school account
 * options for payment which parents sees, a school can add more than one
 * account details and the parent sees all"). Managed from the same Fee
 * Records screen that already hosts fee items and installment plans, not
 * a separate nav entry, matching how installment plans reuse that screen
 * too. Purely informational to a parent submitting a payment, never
 * itself validated against a Payment.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class BankAccountController extends Controller
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

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');

        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:120'],
            'account_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:40'],
        ]);
        $data['sort_order'] = (int) BankAccount::max('sort_order') + 1;

        BankAccount::create($data);

        return back()->with('status', "\"{$data['bank_name']}\" added. Parents submitting a payment will now see it.");
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $account = BankAccount::findOrFail($request->route('bankAccountId'));

        $data = $request->validate([
            'bank_name' => ['required', 'string', 'max:120'],
            'account_name' => ['required', 'string', 'max:120'],
            'account_number' => ['required', 'string', 'max:40'],
        ]);

        $account->update($data);

        return back()->with('status', "\"{$account->bank_name}\" updated.");
    }

    public function toggleActive(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.update');
        $account = BankAccount::findOrFail($request->route('bankAccountId'));
        $account->update(['is_active' => ! $account->is_active]);

        return back()->with('status', $account->is_active
            ? "\"{$account->bank_name}\" is visible to parents again."
            : "\"{$account->bank_name}\" is hidden from parents.");
    }

    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'finance.delete');
        $account = BankAccount::findOrFail($request->route('bankAccountId'));
        $name = $account->bank_name;
        $account->delete();

        return back()->with('status', "\"{$name}\" removed.");
    }

    /** Bulk hide/show/delete for ticked bank accounts, matching the standing bulk-actions convention every list screen follows. */
    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'bank_account_ids' => ['required', 'array', 'min:1'],
            'bank_account_ids.*' => ['exists:tenant.bank_accounts,id'],
            'action' => ['required', 'in:activate,retire,delete'],
        ]);

        if ($data['action'] === 'delete') {
            $this->authorizeAction($request, 'finance.delete');
            $count = BankAccount::whereIn('id', $data['bank_account_ids'])->delete();

            return back()->with('status', "{$count} bank account(s) removed.");
        }

        $this->authorizeAction($request, 'finance.update');
        $count = BankAccount::whereIn('id', $data['bank_account_ids'])->update(['is_active' => $data['action'] === 'activate']);

        return back()->with('status', "{$count} bank account(s) updated.");
    }
}
