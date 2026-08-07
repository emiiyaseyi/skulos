<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\BillingWaiver;
use App\Models\Module;
use App\Models\Plan;
use App\Models\School;
use App\Models\SchoolAddOn;
use App\Models\SubscriptionEvent;
use App\Services\Billing\SubscriptionService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
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
            // Paginated (audit follow-up 2026-07-30): grows with every
            // provisioned school, same unbounded table as the Schools index.
            'schools' => School::with('plan')->orderBy('name')->paginate(30)->withQueryString(),
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

    /**
     * A negotiated discount/promo/correction for ONE school's cost per
     * student per term, independent of its Plan (2026-08-07, user
     * directive: "it should also be able to change a school plan amount
     * if its needs to increase or decrease the cost per child per
     * term") — unlike assignPlan(), this never touches the school's
     * feature/module set, only what SubscriptionService::amountDue()
     * bills them. A blank/null value clears the override, falling back
     * to the plan's own price.
     */
    public function updateSchoolPrice(Request $request, School $school): \Illuminate\Http\RedirectResponse
    {
        // A "Clear override" submit posts an empty string, not the field's
        // absence, so it must be normalised to null before validation, the
        // same fix PaymentController::updateActiveTerm() already needed.
        if ($request->input('price_per_student_per_term') === '') {
            $request->merge(['price_per_student_per_term' => null]);
        }
        $data = $request->validate(['price_per_student_per_term' => ['nullable', 'numeric', 'min:0']]);

        $school->update(['price_per_student_per_term_override' => $data['price_per_student_per_term'] ?? null]);

        return back()->with('status', $data['price_per_student_per_term'] !== null
            ? "{$school->name}'s price per student per term set to ₦".number_format((float) $data['price_per_student_per_term'], 2).'.'
            : "{$school->name} reset to its plan's own price.");
    }

    /** Same as updateSchoolPrice(), applied to every ticked school at once — the same bulk-select pattern already used on the Schools index (SchoolAdminController::bulkAction()). */
    public function bulkUpdateSchoolPrice(Request $request): \Illuminate\Http\RedirectResponse
    {
        if ($request->input('price_per_student_per_term') === '') {
            $request->merge(['price_per_student_per_term' => null]);
        }
        $data = $request->validate([
            'school_ids' => ['required', 'array', 'min:1'],
            'school_ids.*' => ['exists:schools,id'],
            'price_per_student_per_term' => ['nullable', 'numeric', 'min:0'],
        ]);

        $count = School::whereIn('id', $data['school_ids'])->update([
            'price_per_student_per_term_override' => $data['price_per_student_per_term'] ?? null,
        ]);

        return back()->with('status', $data['price_per_student_per_term'] !== null
            ? "{$count} school(s) set to ₦".number_format((float) $data['price_per_student_per_term'], 2).' per student per term.'
            : "{$count} school(s) reset to their plan's own price.");
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

    /**
     * Actually waives billing (user directive 2026-08-07: "fix this so
     * that the platform owner/admin can give it to a school" — a waiver
     * used to only ever create a BillingWaiver label, never consulted by
     * SubscriptionService::amountDue()/billingSnapshot() and never
     * moving next_billing_date, so "marking a term free" changed nothing
     * a school was actually billed). Now pushes next_billing_date
     * forward by the given number of days, the same base-date logic
     * SubscriptionController::extend() already uses, and reinstates a
     * billing-suspended school the same courtesy renew() already gives a
     * school that pays — waiving is functionally equivalent to paying.
     */
    public function addWaiver(Request $request, School $school, SubscriptionService $subs): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'label' => ['required', 'string', 'max:150'],
            'note' => ['nullable', 'string', 'max:500'],
            'days' => ['required', 'integer', 'min:1', 'max:730'],
        ]);

        // (int) cast required: the 'integer' rule only validates format,
        // it never casts, and a real HTTP body is always a string —
        // found live via the exact same TypeError extend() itself was
        // also silently carrying (see that method's own comment).
        $base = $school->next_billing_date && $school->next_billing_date->isFuture() ? $school->next_billing_date : now();
        $newDate = $base->copy()->addDays((int) $data['days']);

        DB::transaction(function () use ($school, $data, $newDate) {
            BillingWaiver::create([
                'school_id' => $school->id, 'label' => $data['label'], 'note' => $data['note'] ?? null, 'granted_by' => Auth::id(),
            ]);

            $updates = ['next_billing_date' => $newDate];
            if ($school->status === School::STATUS_SUSPENDED && $school->suspension_reason === School::SUSPENSION_REASON_BILLING) {
                $updates['status'] = School::STATUS_ACTIVE;
                $updates['suspension_reason'] = null;
            }
            $school->update($updates);

            SubscriptionEvent::create([
                'school_id' => $school->id, 'type' => SubscriptionEvent::TYPE_WAIVER,
                'note' => trim("{$data['label']} ({$data['days']} day(s) waived).".($data['note'] ?? '' ? ' '.$data['note'] : '')),
                'extended_to' => $newDate, 'actioned_by_user_id' => Auth::id(),
            ]);
        });

        $subs->notifySchoolLeadership($school, 'subscription_extended', 'A billing period has been waived',
            "Good news: \"{$data['label']}\" has been marked free for your school. Your next payment is now due {$newDate->format('d M Y')}.");

        return back()->with('status', "\"{$data['label']}\" waived for {$school->name} — next payment now due {$newDate->format('d M Y')}.");
    }

    /**
     * Deletes the record only — a wrong grant's next_billing_date push is
     * NOT reversed here (this is a correction to the audit trail, not an
     * undo of a billing action); use extend()/demand() on the school's
     * own Subscriptions page to correct the date if truly needed, the
     * same as any other billing action already has to be corrected.
     */
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
