<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\MailLog;
use App\Models\School;
use Illuminate\Http\Request;

/**
 * Every email the platform has sent, across every school (2026-08-16).
 * The school-facing equivalent is School\MailLogController, which shows
 * a school only its own rows.
 */
class MailLogController extends Controller
{
    public function index(Request $request): \Illuminate\View\View
    {
        $status = $request->query('status');
        $schoolId = $request->integer('school_id') ?: null;
        $search = trim((string) $request->query('q'));

        $query = MailLog::with('school')->latest();

        if ($status === 'opened') {
            $query->whereNotNull('opened_at');
        } elseif (in_array($status, [MailLog::STATUS_SENT, MailLog::STATUS_FAILED, MailLog::STATUS_SENDING], true)) {
            $query->where('status', $status);
        }
        if ($schoolId) {
            $query->where('school_id', $schoolId);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('recipient', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        return view('admin.mail-log', [
            // Counted with their own queries, not from the paginated
            // collection, so the tiles stay true platform-wide totals
            // rather than "totals for whichever page you are looking at".
            'totals' => [
                'all' => MailLog::count(),
                'sent' => MailLog::where('status', MailLog::STATUS_SENT)->count(),
                'failed' => MailLog::where('status', MailLog::STATUS_FAILED)->count(),
                'opened' => MailLog::whereNotNull('opened_at')->count(),
            ],
            'logs' => $query->paginate(50)->withQueryString(),
            'schools' => School::orderBy('name')->get(['id', 'name']),
            'status' => $status,
            'schoolId' => $schoolId,
            'search' => $search,
        ]);
    }
}
