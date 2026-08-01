<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtPrecheckSession;
use App\Models\Tenant\CbtSession;
use App\Services\Cbt\BreakRequestService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SKULOS Assessment Hub, Phase 2g: a live grid of every attempt
 * currently running (plus who's still mid pre-exam wizard), with
 * risk pills, an inline risk-trend sparkline, and break approve/deny
 * actions. Gated on assessment_hub.create (the same admin tier as the
 * question bank/paper builder/security profiles/face approvals) — a
 * screen showing every taker's live risk data is not something a
 * student's own .view grant should ever reach.
 */
class CbtLiveMonitorController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), 'assessment_hub.create'),
            403,
            'You do not have permission for this.'
        );
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $sessions = CbtSession::with(['assessment', 'takeable', 'securityEvents'])
            ->where('status', CbtSession::STATUS_IN_PROGRESS)
            ->orderByDesc('risk_score')
            ->get();

        $prechecks = CbtPrecheckSession::with(['assessment', 'takeable'])
            ->where('status', CbtPrecheckSession::STATUS_IN_PROGRESS)
            ->orderByDesc('id')
            ->get();

        return view('school.cbt.live-monitor.index', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'sessions' => $sessions,
            'prechecks' => $prechecks,
        ]);
    }

    public function approveBreak(Request $request, BreakRequestService $breaks): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $session = CbtSession::findOrFail($request->route('sessionId'));

        $data = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:60']]);
        $breaks->approve($session, $data['minutes']);

        return redirect()->route('school.cbt.live-monitor.index', ['school' => $school->slug])
            ->with('status', 'Break approved.');
    }

    public function denyBreak(Request $request, BreakRequestService $breaks): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $session = CbtSession::findOrFail($request->route('sessionId'));

        $breaks->deny($session);

        return redirect()->route('school.cbt.live-monitor.index', ['school' => $school->slug])
            ->with('status', 'Break request denied.');
    }
}
