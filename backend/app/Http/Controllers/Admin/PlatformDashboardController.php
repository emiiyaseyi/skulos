<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\School;
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
    public function index(): \Illuminate\View\View
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

        return view('admin.dashboard', [
            'totalSchools' => $schools->count(),
            'byStatus' => $byStatus,
            'donutPct' => $donutPct,
            'totalAdministrators' => DB::table('school_user')->distinct('user_id')->count('user_id'),
            'recentSchools' => $schools->sortByDesc('created_at')->take(5)->values(),
            'recentActivity' => AuditLog::orderByDesc('created_at')->limit(8)->get(),
            'trashedCount' => School::onlyTrashed()->count(),
        ]);
    }
}
