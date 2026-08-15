<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;

/**
 * School-facing audit trail (2026-08-15, user request: "the school admin
 * should have access to the audit trails to see who accessed what, when
 * someone signed in, what they did... admin can see all for all users
 * and date/time stamp"). Previously only the PLATFORM admin had an audit
 * screen; a school could not see its own.
 *
 * Gated on school_info.manage_settings — the same admin tier that
 * already governs the rest of a school's own administrative settings —
 * deliberately rather than a brand new permission, so an existing
 * school's owner/admin roles get it immediately without the
 * "new permission never reaches an already-cloned role" resync gotcha
 * this project has hit repeatedly.
 */
class AuditTrailController extends Controller
{
    public function index(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        $this->authorizeAction($request, 'school_info.manage_settings');

        $userId = $request->query('user_id');
        $action = $request->query('action');
        $from = $request->query('from');
        $to = $request->query('to');

        $logs = DB::connection('tenant')->table('audit_logs')
            ->when($userId, fn ($q) => $q->where('user_id', $userId))
            ->when($action, fn ($q) => $q->where('action', 'like', "%{$action}%"))
            ->when($from, fn ($q) => $q->whereDate('created_at', '>=', $from))
            ->when($to, fn ($q) => $q->whereDate('created_at', '<=', $to))
            ->orderByDesc('id')
            ->paginate(50)
            ->withQueryString();

        // One lookup for the page's user ids rather than per row: these
        // live in the PLATFORM database, so a relation cannot resolve
        // them from the tenant connection.
        $userNames = \App\Models\User::whereIn('id', collect($logs->items())->pluck('user_id')->filter()->unique())
            ->pluck('name', 'id');

        $archives = collect(Storage::disk('local')->files("audit-archives/{$school->slug}"))
            ->map(fn ($path) => [
                'period' => basename($path, '.csv'),
                'bytes' => Storage::disk('local')->size($path),
            ])
            ->sortByDesc('period')
            ->values();

        return view('school.audit-trail', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'logs' => $logs,
            'userNames' => $userNames,
            'archives' => $archives,
            'filters' => compact('userId', 'action', 'from', 'to'),
            'retentionDays' => app(\App\Services\Maintenance\AuditArchiveService::class)
                ->effectiveDays(app(\App\Services\Maintenance\RetentionService::class)),
        ]);
    }

    /**
     * Serves an archived month's CSV. Reachable two ways, both
     * authorised: a signed link from the monthly mail (valid 30 days),
     * or a normal click from the screen above by an admin who already
     * holds school_info.manage_settings.
     */
    public function download(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $school = $this->school($request);

        if (! $request->hasValidSignature()) {
            $this->authorizeAction($request, 'school_info.manage_settings');
        }

        // basename() so a crafted period can never climb out of the
        // school's own archive directory.
        $period = basename((string) $request->route('period'));
        $path = "audit-archives/{$school->slug}/{$period}.csv";

        abort_unless(Storage::disk('local')->exists($path), 404);

        return Storage::disk('local')->download($path, "{$school->slug}-{$period}-audit-trail.csv");
    }
}
