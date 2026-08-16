<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\MailLog;
use App\Models\School;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A school's own outgoing email record (2026-08-16): every message
 * SKULOS sent on its behalf, and whether it actually went out.
 *
 * Scoped hard to this school's own rows. MailLog lives in the platform
 * database (mail is sent platform-wide, often before any tenant
 * connection exists), so unlike a tenant-scoped model there is no
 * database boundary doing the isolation here — the school_id filter is
 * the only thing standing between one school and another's recipients,
 * which is why it is applied on the base query before anything else
 * rather than as one condition among several.
 */
class MailLogController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }

    public function index(Request $request): \Illuminate\View\View
    {
        // Same tier as the school's other communication oversight.
        $this->authorizeAction($request, 'communication.view');
        $school = $this->school($request);

        $base = MailLog::where('school_id', $school->id);

        $status = $request->query('status');
        $search = trim((string) $request->query('q'));

        $query = (clone $base)->latest();
        if (in_array($status, [MailLog::STATUS_SENT, MailLog::STATUS_FAILED, MailLog::STATUS_SENDING], true)) {
            $query->where('status', $status);
        }
        if ($search !== '') {
            $query->where(function ($q) use ($search) {
                $q->where('recipient', 'like', "%{$search}%")
                    ->orWhere('subject', 'like', "%{$search}%");
            });
        }

        return view('school.mail-log', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'totals' => [
                'all' => (clone $base)->count(),
                'sent' => (clone $base)->where('status', MailLog::STATUS_SENT)->count(),
                'failed' => (clone $base)->where('status', MailLog::STATUS_FAILED)->count(),
            ],
            'logs' => $query->paginate(50)->withQueryString(),
            'status' => $status,
            'search' => $search,
        ]);
    }
}
