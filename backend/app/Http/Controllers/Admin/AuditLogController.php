<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\AuditLog;
use App\Models\User;
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
}
