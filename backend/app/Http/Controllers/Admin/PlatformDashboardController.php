<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\Backup;
use App\Models\Plan;
use App\Models\Role;
use App\Models\School;
use App\Models\TrialRequest;
use App\Models\User;
use App\Services\Platform\CrossSchoolAggregates;
use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\DB;

/**
 * The platform wide Dashboard: analytics and recent activity across every
 * school, distinct from the Schools section (user request 2026-07-09,
 * "separate the dashboard and school section... different information").
 * SchoolAdminController::schools() is the school by school management
 * list; this is the bird's eye view.
 */
class PlatformDashboardController extends Controller
{
    public function index(CrossSchoolAggregates $aggregates, TenantManager $tenants): \Illuminate\View\View
    {
        $schools = School::all();

        $byStatus = [
            'active' => $schools->where('status', School::STATUS_ACTIVE)->count(),
            'suspended' => $schools->where('status', School::STATUS_SUSPENDED)->count(),
            'archived' => $schools->where('status', School::STATUS_ARCHIVED)->count(),
            'pending' => $schools->where('status', School::STATUS_PENDING)->count(),
        ];
        $total = max($schools->count(), 1);
        $donutPct = [
            'active' => round($byStatus['active'] / $total * 100, 2),
            'suspended' => round($byStatus['suspended'] / $total * 100, 2),
            'archived' => round($byStatus['archived'] / $total * 100, 2),
        ];

        $trialRequests = TrialRequest::all();
        $approvedTrials = $trialRequests->where('status', TrialRequest::STATUS_APPROVED)->count();
        $conversionRate = $trialRequests->count() ? round($approvedTrials / $trialRequests->count() * 100) : null;

        $plansById = Plan::all()->keyBy('id');
        $bySubscription = $schools->groupBy('plan_id')->map->count();
        $subscriptionBreakdown = $bySubscription->map(fn ($count, $planId) => [
            'plan' => $planId ? ($plansById[$planId]->name ?? 'Unknown plan') : 'No plan assigned',
            'count' => $count,
        ])->sortByDesc('count')->values();
        // The breakdown above already has every number needed for one
        // summary total (Dashboard Design Cross-Check, 2026-07-13:
        // "shown as a breakdown, not one summary number"), schools with no
        // plan assigned yet are not an active subscription.
        $activeSubscriptions = $schools->whereNotNull('plan_id')->count();

        $crossSchoolTotals = $aggregates->totals($tenants);

        $schoolsWithoutBackup = $schools->count() - Backup::whereIn('school_id', $schools->pluck('id'))->distinct('school_id')->count('school_id');

        // School growth over the last 6 months, counted in PHP against the
        // already loaded $schools collection rather than a database date
        // function, to stay MySQL/SQLite agnostic per the project's
        // standing database rule.
        $growthMonths = collect(range(5, 0))->map(function ($monthsAgo) use ($schools) {
            $month = now()->subMonths($monthsAgo);
            return [
                'label' => $month->format('M'),
                'count' => $schools->filter(fn ($s) => $s->created_at && $s->created_at->isSameMonth($month) && $s->created_at->isSameYear($month))->count(),
            ];
        });
        $growthMax = max(1, $growthMonths->max('count'));

        // Admin tier role slugs, the same set RoleDashboard::admin() treats
        // as "owner/leadership", not every user attached to a school (a
        // real bug found live 2026-07-12: "how can i have 449 users and
        // 447 administrators" — the previous query counted every
        // student/parent/teacher account too, since they are all rows in
        // school_user regardless of role).
        $adminRoleIds = Role::whereIn('slug', [...config('skulos.leadership_role_slugs'), 'school-admin'])->pluck('id');
        $totalAdministrators = DB::table('school_user')->whereIn('role_id', $adminRoleIds)->distinct('user_id')->count('user_id');

        return view('admin.dashboard', [
            'totalSchools' => $schools->count(),
            'byStatus' => $byStatus,
            'donutPct' => $donutPct,
            'totalAdministrators' => $totalAdministrators,
            'totalUsers' => User::count(),
            'recentSchools' => $schools->sortByDesc('created_at')->take(5)->values(),
            'recentActivity' => AuditLog::orderByDesc('created_at')->limit(8)->get(),
            'trashedCount' => School::onlyTrashed()->count(),
            'pendingTrials' => TrialRequest::pending()->count(),
            'conversionRate' => $conversionRate,
            'subscriptionBreakdown' => $subscriptionBreakdown,
            'schoolsWithoutBackup' => max(0, $schoolsWithoutBackup),
            'growthMonths' => $growthMonths,
            'growthMax' => $growthMax,
            'activeSubscriptions' => $activeSubscriptions,
            'crossSchoolTotals' => $crossSchoolTotals,
        ]);
    }
}
