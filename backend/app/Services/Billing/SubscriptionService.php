<?php

namespace App\Services\Billing;

use App\Models\Plan;
use App\Models\School;
use App\Services\Tenancy\TenantManager;

/**
 * A school's own SKULOS subscription: how much it owes next, and when
 * (user request 2026-07-17: "add a countdown subscription... amount
 * due (number of current learner determines this multiply by the cost
 * of the plan)"). Distinct from the Foundation Finance slice, which is
 * a parent paying a school; this is a school paying the platform.
 */
class SubscriptionService
{
    public function __construct(protected TenantManager $tenants) {}

    /** The school's own plan, falling back to the platform default the same way School::hasFeature() already does. */
    public function plan(School $school): ?Plan
    {
        return $school->plan ?? Plan::where('is_default', true)->first();
    }

    /**
     * The price per student per term this school is actually billed at
     * (2026-08-07, user directive: "change a school plan amount if its
     * needs to increase or decrease the cost per child per term"). A
     * school's own price_per_student_per_term_override, when set,
     * replaces the plan's price for billing purposes only — it does not
     * change which features/modules the school has, those still come
     * entirely from the assigned Plan. Null if there is no plan to price
     * against at all (matches amountDue()'s own existing null case).
     */
    public function effectivePrice(School $school): ?float
    {
        if ($school->price_per_student_per_term_override !== null) {
            return (float) $school->price_per_student_per_term_override;
        }

        $plan = $this->plan($school);

        return $plan ? (float) $plan->price_per_student_per_term : null;
    }

    /** Real active learner count, read from the school's own tenant database. */
    public function activeLearnerCount(School $school): int
    {
        if (! $school->isProvisioned()) {
            return 0;
        }

        return $this->tenants->connectTo($school, fn () => \App\Models\Tenant\Student::active()->count());
    }

    /** Active learners × the school's effective per-student, per-term price (its own override, or the plan's price). Null if there is no plan/price to bill against at all. */
    public function amountDue(School $school): ?float
    {
        $price = $this->effectivePrice($school);
        if ($price === null) {
            return null;
        }

        return $this->activeLearnerCount($school) * $price;
    }

    /**
     * learners + amountDue + daysUntilDue in a single tenant-DB connection
     * switch, for callers (the admin subscriptions list/show pages) that
     * would otherwise call activeLearnerCount() once directly and again
     * indirectly via amountDue(), doubling the tenant-connection churn per
     * school on every page load (audit finding, 2026-07-17).
     */
    public function billingSnapshot(School $school): array
    {
        $learners = $this->activeLearnerCount($school);
        $price = $this->effectivePrice($school);

        return [
            'learners' => $learners,
            'amountDue' => $price !== null ? $learners * $price : null,
            'daysUntilDue' => $this->daysUntilDue($school),
        ];
    }

    /** Whole days until next_billing_date, negative once overdue, null if no due date is set at all. */
    public function daysUntilDue(School $school): ?int
    {
        if (! $school->next_billing_date) {
            return null;
        }

        return (int) now()->startOfDay()->diffInDays($school->next_billing_date->startOfDay(), false);
    }

    /** Final 21 days before (and any day on/after) the due date, the window the daily reminder popup shows in. */
    public function isWithinReminderWindow(School $school): bool
    {
        $days = $this->daysUntilDue($school);

        return $days !== null && $days <= 21;
    }

    /**
     * Sum of every verified payment not yet applied to a renewal (user
     * request 2026-07-19: a school's amount due changed mid cycle after
     * a plan/learner-count change, was paid across two separate verified
     * payments, and the admin needed a way to confirm that total and
     * renew). "Applied" is tracked per payment (applied_to_renewal_at)
     * rather than by date range, so a renewal never double counts a
     * payment an earlier renewal already consumed.
     */
    public function verifiedUnappliedTotal(School $school): float
    {
        return (float) \App\Models\SubscriptionPayment::where('school_id', $school->id)
            ->where('status', \App\Models\SubscriptionPayment::STATUS_VERIFIED)
            ->whereNull('applied_to_renewal_at')
            ->sum('amount');
    }

    /**
     * Notifies every active member of the school holding one of
     * config('skulos.subscription_visible_role_slugs') (Owner/Principal/
     * VP/Head Teacher/School Admin), writing a real Notification row
     * into the school's own tenant database, the same platform-to-tenant
     * delivery pattern PlatformBroadcastService::deliver() already uses.
     */
    public function notifySchoolLeadership(School $school, string $category, string $title, string $body): void
    {
        if (! $school->isProvisioned()) {
            return;
        }

        $roleIds = \App\Models\Role::where('school_id', $school->id)
            ->whereIn('slug', config('skulos.subscription_visible_role_slugs', []))
            ->pluck('id');
        if ($roleIds->isEmpty()) {
            return;
        }

        $memberIds = $school->users()->wherePivotIn('role_id', $roleIds)->wherePivot('status', 'active')->pluck('users.id');
        if ($memberIds->isEmpty()) {
            return;
        }

        // A failure creating any one recipient's notification (e.g. a
        // locked/contended tenant DB) previously aborted the whole loop
        // and threw back to the caller, who by this point had already
        // committed the actual billing state change (payment decided/
        // extended/locked/reinstated) — the request then 500'd with no
        // signal of which recipients, if any, were actually notified
        // (audit finding M-5, 2026-07-17). Each recipient is now
        // isolated: one failure is logged and skipped, the rest still go
        // out, and the state change this is called after always stands
        // regardless.
        $this->tenants->connectTo($school, function () use ($memberIds, $category, $title, $body, $school) {
            foreach ($memberIds as $userId) {
                try {
                    \App\Models\Tenant\Notification::create([
                        'user_id' => $userId,
                        'category' => $category,
                        'type' => \App\Models\Tenant\Notification::typeFor($category),
                        'title' => $title,
                        'body' => $body,
                    ]);
                } catch (\Throwable $e) {
                    \Illuminate\Support\Facades\Log::error("Failed to notify user {$userId} in school {$school->slug} ({$category}): ".$e->getMessage());
                }
            }
        });
    }
}
