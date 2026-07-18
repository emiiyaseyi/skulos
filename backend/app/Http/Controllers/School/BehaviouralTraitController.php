<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\BehaviouralTrait;
use App\Services\Rbac\Rbac;
use App\Services\Results\BehaviouralTraitCatalog;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Admin-configurable behavioural/psychomotor trait catalog (user request
 * 2026-07-18: "the admin set it" whether each trait is a remark or a
 * score). Seeded once from config('skulos.default_behavioural_traits'),
 * this real tenant table is the only thing ever read at render time.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class BehaviouralTraitController extends Controller
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

    public function index(Request $request, BehaviouralTraitCatalog $catalog): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $catalog->ensureSeeded();
        $school = $this->school($request);

        return view('school.report-cards.traits', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'traits' => BehaviouralTrait::orderBy('sequence')->orderBy('id')->get(),
            'remarkScale' => config('skulos.behavioural_trait_remark_scale', []),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'report_cards.assign_review'),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in([BehaviouralTrait::TYPE_REMARK, BehaviouralTrait::TYPE_SCORE])],
            'max_score' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        BehaviouralTrait::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'max_score' => $data['type'] === BehaviouralTrait::TYPE_SCORE ? ($data['max_score'] ?? 5) : null,
            'sequence' => (int) BehaviouralTrait::max('sequence') + 1,
        ]);

        return back()->with('status', "\"{$data['name']}\" added to the behavioural traits list.");
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $trait = BehaviouralTrait::findOrFail($request->route('traitId'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:120'],
            'type' => ['required', Rule::in([BehaviouralTrait::TYPE_REMARK, BehaviouralTrait::TYPE_SCORE])],
            'max_score' => ['nullable', 'integer', 'min:1', 'max:1000'],
        ]);

        $trait->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'max_score' => $data['type'] === BehaviouralTrait::TYPE_SCORE ? ($data['max_score'] ?? 5) : null,
        ]);

        return back()->with('status', "\"{$trait->name}\" updated.");
    }

    public function toggleActive(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $trait = BehaviouralTrait::findOrFail($request->route('traitId'));
        $trait->update(['is_active' => ! $trait->is_active]);

        return back()->with('status', $trait->is_active
            ? "\"{$trait->name}\" is active again."
            : "\"{$trait->name}\" is retired. Already-entered ratings for it are kept.");
    }

    /** Bulk retire/reactivate for ticked traits, matching the standing bulk-actions convention every list screen follows. */
    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $data = $request->validate([
            'trait_ids' => ['required', 'array', 'min:1'],
            'trait_ids.*' => ['exists:tenant.behavioural_traits,id'],
            'action' => ['required', 'in:activate,retire'],
        ]);

        $count = BehaviouralTrait::whereIn('id', $data['trait_ids'])->update(['is_active' => $data['action'] === 'activate']);

        return back()->with('status', "{$count} trait(s) updated.");
    }
}
