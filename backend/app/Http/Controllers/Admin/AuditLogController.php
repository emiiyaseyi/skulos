<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Auditing\IntegrityChecker;
use Illuminate\Http\Request;

/**
 * View Audit Logs (Dashboard Design Cross-Check, 2026-07-13: "Partial,
 * Recent activity is already inline on the dashboard, no separate full
 * log browser page yet"). The dashboard's own list is capped at 8 rows
 * with no way to search or go further back; this is the real browser:
 * filter by who did it, what action, and a date range, paginated.
 */
class AuditLogController extends Controller
{
    public function index(Request $request): \Illuminate\View\View
    {
        $userId = $request->query('user_id');
        $action = trim((string) $request->query('action'));
        $from = $request->query('from');
        $to = $request->query('to');

        $logs = AuditLog::query()
            ->with('user')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($action !== '', fn ($q) => $q->where('action', 'like', "%{$action}%"))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('created_at')
            ->paginate(40)
            ->withQueryString();

        return view('admin.audit-log', [
            'logs' => $logs,
            'userId' => $userId,
            'action' => $action,
            'from' => $from,
            'to' => $to,
            // A cheap, bounded list for the filter dropdown, not a scan of
            // every distinct user_id ever logged (would grow unbounded on
            // a large audit_logs table). Platform level actions are almost
            // always taken by a super admin anyway.
            'users' => User::where('is_super_admin', true)->orderBy('name')->get(['id', 'name', 'email']),
        ]);
    }

    /**
     * On-demand check that the platform audit_logs hash chain is unbroken
     * (2026-07-20 audit log hardening). Scoped to the platform connection
     * only, this page's own data; `php artisan audit-logs:verify` (no
     * --connection) is the scheduled, every-school version of this same
     * check.
     */
    public function verify(Request $request, IntegrityChecker $checker): \Illuminate\Http\RedirectResponse
    {
        $result = $checker->verify(config('database.default'));

        if ($result['ok']) {
            return back()->with('status', "Integrity check passed: all {$result['checked']} platform audit log row(s) are unbroken.");
        }

        return back()->with('error', "Integrity check FAILED at audit_logs.id={$result['broken_at_id']} ({$result['reason']}). {$result['checked']} row(s) were checked before the break was found.");
    }
}
