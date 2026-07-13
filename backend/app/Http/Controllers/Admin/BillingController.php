<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingWaiver;
use App\Models\Module;
use App\Models\Plan;
use App\Models\School;
use App\Models\SchoolAddOn;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;

/**
 * Billing (tracker section 14, sourced from "SKULOS PRICING PLAN AND
 * FEATURES.docx"): per student, per term pricing plans that bundle a
 * customer-facing feature checklist and a set of RBAC modules, per-school
 * plan assignment (upgrade/downgrade), ad hoc extra features beyond the
 * plan, an add on marketplace purchasable on any plan, and free-term
 * waivers. Core modules (tracker section 5) are always available
 * regardless of plan or billing state; this only ever gates the
 * optional/toggleable ones (config('skulos.modules'), core: false).
 */
class BillingController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        return view('admin.billing', [
            'plans' => Plan::orderBy('sort_order')->get(),
            'schools' => School::with('plan')->orderBy('name')->get(),
            'optionalModules' => $this->optionalModules(),
            'featureCatalog' => config('skulos.plan_feature_catalog'),
            'addOnCatalog' => config('skulos.add_on_catalog'),
        ]);
    }

    public function storePlan(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $this->validatedPlan($request);
        $data['slug'] = Str::slug($data['name']);

        Plan::create($data + [
            'modules' => $request->input('modules', []),
            'features' => $request->input('features', []),
            'is_active' => true,
            'sort_order' => Plan::max('sort_order') + 1,
        ]);

        return back()->with('status', "{$data['name']} plan created.");
    }

    public function updatePlan(Request $request, Plan $plan): \Illuminate\Http\RedirectResponse
    {
        $data = $this->validatedPlan($request, $plan->id);
        $plan->update($data + [
            'modules' => $request->input('modules', []),
            'features' => $request->input('features', []),
        ]);

        return back()->with('status', "{$plan->name} plan updated.");
    }

    public function togglePlanActive(Plan $plan): \Illuminate\Http\RedirectResponse
    {
        $plan->update(['is_active' => ! $plan->is_active]);

        return back()->with('status', $plan->is_active ? "{$plan->name} is active again." : "{$plan->name} is no longer offered to new schools.");
    }

    /** Coming soon vs actually sellable, independent of active/retired. */
    public function togglePlanPurchasable(Plan $plan): \Illuminate\Http\RedirectResponse
    {
        $plan->update(['is_purchasable' => ! $plan->is_purchasable]);

        return back()->with('status', $plan->is_purchasable
            ? "{$plan->name} can now be sold and assigned to a school."
            : "{$plan->name} is now shown as Coming soon and cannot be assigned to a school.");
    }

    /** Assign, upgrade, or downgrade a school's plan. */
    public function assignPlan(Request $request, School $school, Rbac $rbac): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate(['plan_id' => ['required', 'exists:plans,id']]);
        $plan = Plan::findOrFail($data['plan_id']);

        abort_unless($plan->is_purchasable, 422, "{$plan->name} is not yet available to assign.");

        $school->update(['plan_id' => $plan->id]);

        // The plan's modules are enabled; every other optional module goes
        // back to disabled, so a downgrade actually removes access.
        foreach ($this->optionalModules() as $code => $meta) {
            $rbac->setModuleState($school, $code, in_array($code, $plan->modules ?? [], true)
                ? Module::STATE_ENABLED
                : Module::STATE_DISABLED);
        }

        return back()->with('status', "{$school->name} is now on the {$plan->name} plan.");
    }

    /** Grant or revoke one optional module for a school, regardless of its plan. */
    public function toggleFeature(Request $request, School $school, Rbac $rbac): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'module' => ['required', Rule::in(array_keys($this->optionalModules()))],
        ]);

        $current = $rbac->moduleState($school, $data['module']);
        $next = $current === Module::STATE_ENABLED ? Module::STATE_DISABLED : Module::STATE_ENABLED;
        $rbac->setModuleState($school, $data['module'], $next);

        return back()->with('status', "{$this->optionalModules()[$data['module']]['name']} ".($next === Module::STATE_ENABLED ? 'enabled' : 'disabled')." for {$school->name}.");
    }

    /**
     * Grant an add on to a school (tracker section 14): purchasable on any
     * plan regardless of tier. If the add on maps to an RBAC module, that
     * module is also enabled so the grant is functionally real, not just
     * a record.
     */
    public function grantAddOn(Request $request, School $school, Rbac $rbac): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'add_on_code' => ['required', Rule::in($this->addOnCodes())],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        $addOn = SchoolAddOn::firstOrCreate(
            ['school_id' => $school->id, 'add_on_code' => $data['add_on_code']],
            ['note' => $data['note'] ?? null, 'granted_by' => Auth::id()]
        );

        $moduleCode = $addOn->catalogEntry()['module'] ?? null;
        if ($moduleCode) {
            $rbac->setModuleState($school, $moduleCode, Module::STATE_ENABLED);
        }

        return back()->with('status', ($addOn->catalogEntry()['name'] ?? $data['add_on_code'])." granted to {$school->name}.");
    }

    /**
     * Found 2026-07-11 (user report: "if a school is downgraded, the
     * school menu should also change"): this deleted the SchoolAddOn row
     * but never turned the module back off, so a revoked add-on's screen
     * (and its nav entry) stayed enabled/visible regardless, the mirror
     * image bug of the one grantAddOn() already handled correctly.
     */
    public function revokeAddOn(SchoolAddOn $addOn, Rbac $rbac): \Illuminate\Http\RedirectResponse
    {
        $school = $addOn->school;
        $name = $addOn->catalogEntry()['name'] ?? $addOn->add_on_code;
        $moduleCode = $addOn->catalogEntry()['module'] ?? null;
        $addOn->delete();

        if ($moduleCode) {
            $rbac->setModuleState($school, $moduleCode, Module::STATE_DISABLED);
        }

        return back()->with('status', "{$name} removed from {$school->name}.");
    }

    public function addWaiver(Request $request, School $school): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:500'],
        ]);

        BillingWaiver::create($data + ['school_id' => $school->id, 'granted_by' => Auth::id()]);

        return back()->with('status', "Marked \"{$data['label']}\" free for {$school->name}.");
    }

    public function removeWaiver(BillingWaiver $waiver): \Illuminate\Http\RedirectResponse
    {
        $waiver->delete();

        return back()->with('status', 'Waiver removed.');
    }

    protected function optionalModules(): array
    {
        return Module::optional();
    }

    protected function addOnCodes(): array
    {
        return collect(config('skulos.add_on_catalog'))
            ->flatMap(fn ($category) => array_keys($category['items']))
            ->all();
    }

    protected function featureKeys(): array
    {
        return collect(config('skulos.plan_feature_catalog'))
            ->flatMap(fn ($category) => array_keys($category['features']))
            ->all();
    }

    protected function validatedPlan(Request $request, ?int $ignoreId = null): array
    {
        $data = $request->validate([
            'name' => ['required', 'string', 'max:100'],
            'price_per_student_per_term' => ['required', 'numeric', 'min:0'],
            'storage_limit_mb' => ['nullable', 'integer', 'min:1'],
        ]);

        $request->validate([
            'features' => ['sometimes', 'array'],
            'features.*' => [Rule::in($this->featureKeys())],
            'modules' => ['sometimes', 'array'],
            'modules.*' => [Rule::in(array_keys($this->optionalModules()))],
        ]);

        return $data;
    }
}
