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
            'remarkScale' => \App\Support\TraitRemarkScale::options(),
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
            'domain' => ['nullable', Rule::in([BehaviouralTrait::DOMAIN_PSYCHOMOTOR, BehaviouralTrait::DOMAIN_AFFECTIVE])],
        ]);

        BehaviouralTrait::create([
            'name' => $data['name'],
            'type' => $data['type'],
            'domain' => $data['domain'] ?? null,
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
            'domain' => ['nullable', Rule::in([BehaviouralTrait::DOMAIN_PSYCHOMOTOR, BehaviouralTrait::DOMAIN_AFFECTIVE])],
        ]);

        $trait->update([
            'name' => $data['name'],
            'type' => $data['type'],
            'domain' => $data['domain'] ?? null,
            'max_score' => $data['type'] === BehaviouralTrait::TYPE_SCORE ? ($data['max_score'] ?? 5) : null,
        ]);

        return back()->with('status', "\"{$trait->name}\" updated.");
    }

    /**
     * The words a class teacher picks from for a remark type trait (user
     * directive 2026-09-24: "Fair, Good, Very Good and Excellent... the
     * admin can change them as needed"). One word per line, best kept in
     * the order they should appear in the dropdown.
     */
    public function updateScale(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $data = $request->validate(['options' => ['required', 'string', 'max:500']]);

        $options = collect(preg_split('/\r\n|\r|\n/', $data['options']))
            ->map(fn ($o) => trim($o))
            ->filter(fn ($o) => $o !== '')
            ->unique(fn ($o) => mb_strtolower($o))
            ->values();

        if ($options->isEmpty() || $options->count() > 10 || $options->contains(fn ($o) => mb_strlen($o) > 40)) {
            return back()->withErrors(['options' => 'Give between 1 and 10 words, one per line, each under 40 characters.'])->withInput();
        }

        \App\Support\TraitRemarkScale::save($options->all());

        return back()->with('status', 'Rating options saved: '.$options->implode(', ').'.');
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
