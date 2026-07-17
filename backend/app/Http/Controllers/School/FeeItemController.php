<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\FeeItem;
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
        ]);

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
        ]);

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
}
