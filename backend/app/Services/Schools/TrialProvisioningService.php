<?php

namespace App\Services\Schools;

use App\Mail\TrialRequestStatusMail;
use App\Models\Module;
use App\Models\Plan;
use App\Models\School;
use App\Models\TrialRequest;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use App\Support\TemporaryPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

/**
 * Creates a real, working school from a trial request: the actual tenant
 * database, the owner's login, their role, and a starting plan -- the one
 * place this happens, shared by the instant self-serve path a visitor
 * triggers themselves (TrialRequestController::store(), 2026-11-06) and
 * the platform admin's own manual "Approve" button (kept for retrying a
 * request whose instant provisioning failed, or for a request an admin
 * chose to leave pending for a closer look first).
 *
 * Direct user instruction behind the self-serve path: "instant access for
 * setup with manual review from the backend" -- a school should not have
 * to wait on a human before they can start using what was promised as a
 * free trial; the backend review queue becomes an after-the-fact audit
 * and intervention point (TrialRequestAdminController::reject() can still
 * suspend an already-live school), not a gate in front of access.
 */
class TrialProvisioningService
{
    public function __construct(protected TenantManager $tenants, protected Rbac $rbac)
    {
    }

    /**
     * @return array{school: School, admin: User, password: string}
     */
    public function provision(TrialRequest $trialRequest, ?int $reviewedByUserId = null, ?string $reviewNote = null, ?int $planId = null): array
    {
        $slug = self::uniqueSlug($trialRequest->school_name, $trialRequest->preferred_slug);
        $temporaryPassword = TemporaryPassword::generate();

        $school = School::create([
            'name' => $trialRequest->school_name,
            'slug' => $slug,
            'email' => $trialRequest->email,
            'phone' => $trialRequest->phone,
            // The confirmed free-trial promise is a full year, not an
            // open-ended "until someone notices" state (next_billing_date
            // was otherwise left null by every other school-creation path
            // until an admin set it by hand) -- this makes the year
            // concrete and self-enforcing through the billing reminder
            // and lockout flow that already exists for a real due date.
            'next_billing_date' => now()->addYear(),
        ]);
        $this->tenants->provision($school);

        $admin = User::firstOrNew(['email' => Str::lower($trialRequest->email)]);
        $admin->name = $admin->name ?: $trialRequest->contact_name;
        $admin->password = Hash::make($temporaryPassword);
        $admin->email_verified_at = $admin->email_verified_at ?: now();
        $admin->must_change_password = true;
        $admin->save();

        $ownerRoleId = $this->rbac->cloneTemplateRole($school, 'school-owner')->id;
        $school->users()->syncWithoutDetaching([$admin->id => ['role_id' => $ownerRoleId, 'status' => 'active']]);

        // Default to the cheapest plan that can actually be sold -- "open
        // to low cost schools" (direct instruction) means a brand new,
        // self-served trial should not sit on no plan at all (the
        // "No plan set" gap this project's own history already found and
        // fixed once for the manual-approval path). An admin retrying a
        // failed instant provision can still pick a specific plan.
        $plan = $planId
            ? Plan::where('id', $planId)->where('is_purchasable', true)->first()
            : Plan::where('is_purchasable', true)->orderBy('price_per_student_per_term')->first();
        if ($plan) {
            $school->update(['plan_id' => $plan->id]);
            foreach (Module::optional() as $code => $meta) {
                $this->rbac->setModuleState($school, $code, in_array($code, $plan->modules ?? [], true)
                    ? Module::STATE_ENABLED
                    : Module::STATE_DISABLED);
            }
        }

        $trialRequest->update([
            'status' => TrialRequest::STATUS_APPROVED,
            'review_note' => $reviewNote,
            'reviewed_by' => $reviewedByUserId,
            'reviewed_at' => now(),
            'school_id' => $school->id,
        ]);

        $credentials = [
            'portal_url' => $school->portalUrl('/login'),
            'email' => $admin->email,
            'password' => $temporaryPassword,
        ];

        \Illuminate\Support\Facades\Mail::to($trialRequest->email)->send(new TrialRequestStatusMail($trialRequest, $credentials));

        return ['school' => $school, 'admin' => $admin, 'password' => $temporaryPassword];
    }

    /**
     * A school's subdomain. No separator (2026-08-16, direct instruction):
     * Str::slug()'s default turns "Bright Star Academy" into
     * "bright-star-academy", and a hyphenated subdomain is harder to say
     * aloud, easier to mistype, and looks worse on a letterhead than
     * "brightstaracademy". The numeric suffix a clash falls back to is
     * appended for the same reason without one.
     */
    public static function uniqueSlug(string $name, ?string $preferred = null): string
    {
        $base = Str::slug($preferred ?: $name, '') ?: 'school';
        $slug = $base;
        $suffix = 1;

        while (School::where('slug', $slug)->exists()) {
            $slug = $base.(++$suffix);
        }

        return $slug;
    }
}
