<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtIncident;
use App\Models\Tenant\CbtSession;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * SKULOS Assessment Hub, Phase 2g: incidents raised automatically (a
 * session's risk crossing "high", see RiskScoringService) or manually
 * by staff from the live monitor. Same open/resolved shape as every
 * other approval-style state machine in this app. Gated on
 * assessment_hub.export (corrected 2026-08-05 from .create, see
 * CbtAdminConsoleController's own docblock).
 */
class CbtIncidentController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), 'assessment_hub.export'),
            403,
            'You do not have permission for this.'
        );
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $incidents = CbtIncident::with(['session.assessment', 'session.takeable'])
            ->orderByRaw("status = 'open' desc")
            ->orderByDesc('id')
            ->get();

        return view('school.cbt.incidents.index', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'incidents' => $incidents,
        ]);
    }

    public function raise(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $session = CbtSession::findOrFail($request->route('sessionId'));

        $data = $request->validate(['description' => ['required', 'string', 'max:500']]);

        CbtIncident::create([
            'session_id' => $session->id,
            'type' => 'manual',
            'severity' => 'info',
            'description' => $data['description'],
            'raised_by_user_id' => Auth::id(),
        ]);

        return redirect()->route('school.cbt.incidents.index', ['school' => $school->slug])
            ->with('status', 'Incident raised.');
    }

    public function resolve(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $incident = CbtIncident::findOrFail($request->route('incidentId'));

        $data = $request->validate(['notes' => ['nullable', 'string', 'max:500']]);

        $updated = CbtIncident::where('id', $incident->id)->where('status', CbtIncident::STATUS_OPEN)->update([
            'status' => CbtIncident::STATUS_RESOLVED,
            'resolved_by_user_id' => Auth::id(),
            'resolved_at' => now(),
            'notes' => $data['notes'] ?? null,
        ]);
        abort_if($updated === 0, 422, 'This incident has already been resolved.');

        return redirect()->route('school.cbt.incidents.index', ['school' => $school->slug])
            ->with('status', 'Incident resolved.');
    }
}
