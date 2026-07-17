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

    /** Real active learner count, read from the school's own tenant database. */
    public function activeLearnerCount(School $school): int
    {
        if (! $school->isProvisioned()) {
            return 0;
        }

        return $this->tenants->connectTo($school, fn () => \App\Models\Tenant\Student::active()->count());
    }

    /** Active learners × the plan's per-student, per-term price. Null if there is no plan to price against at all. */
    public function amountDue(School $school): ?float
    {
        $plan = $this->plan($school);
        if (! $plan) {
            return null;
        }

        return $this->activeLearnerCount($school) * (float) $plan->price_per_student_per_term;
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
        $plan = $this->plan($school);

        return [
            'learners' => $learners,
            'amountDue' => $plan ? $learners * (float) $plan->price_per_student_per_term : null,
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

        $this->tenants->connectTo($school, function () use ($memberIds, $category, $title, $body) {
            foreach ($memberIds as $userId) {
                \App\Models\Tenant\Notification::create([
                    'user_id' => $userId,
                    'category' => $category,
                    'type' => \App\Models\Tenant\Notification::typeFor($category),
                    'title' => $title,
                    'body' => $body,
                ]);
            }
        });
    }
}
