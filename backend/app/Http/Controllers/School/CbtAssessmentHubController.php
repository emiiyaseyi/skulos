<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtGrader;
use App\Models\Tenant\CbtGraderAssignment;
use App\Models\Tenant\CbtProctor;
use App\Models\Tenant\CbtSchedule;
use App\Models\Tenant\CbtSecurityEvent;
use App\Models\Tenant\CbtSession;
use App\Services\Cbt\AttemptControlService;
use App\Services\Cbt\BreakRequestService;
use App\Services\Cbt\CbtSessionService;
use App\Services\Cbt\GradingDistributionService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The per-assessment admin hub — the reference repo's own admin.php,
 * one screen per assessment with nine tabs. Where the Admin Backend
 * console answers "what is happening across the school", this answers
 * "what is happening on THIS paper", which is the view an exam officer
 * running a single sitting actually wants.
 *
 * Attempt actions reuse AttemptControlService rather than duplicating
 * it, so suspend/reinstate/pause behave identically here and on the
 * console — including giving back time lost to staff decisions.
 */
class CbtAssessmentHubController extends Controller
{
    public const TABS = [
        'overview' => 'Overview',
        'attempts' => 'Attempts',
        'live' => 'Live',
        'proctors' => 'Proctors',
        'graders' => 'Graders',
        'questions' => 'Questions',
        'events' => 'Events',
        'brand' => 'Brand',
        'settings' => 'Settings',
    ];

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

    protected function assessment(Request $request): CbtAssessment
    {
        return CbtAssessment::with(['subject', 'term', 'securityProfile'])
            ->findOrFail($request->route('assessmentId'));
    }

    public function index(Request $request, GradingDistributionService $distribution): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $assessment = $this->assessment($request);

        $tab = (string) $request->query('tab', 'overview');
        if (! array_key_exists($tab, self::TABS)) {
            $tab = 'overview';
        }

        $sessionIds = CbtSession::where('assessment_id', $assessment->id)->pluck('id');

        return view('school.cbt.hub.index', array_merge([
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'assessment' => $assessment,
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => [
                'attempts' => $sessionIds->count(),
                'live' => CbtSession::where('assessment_id', $assessment->id)->where('status', CbtSession::STATUS_IN_PROGRESS)->count(),
                'events' => CbtSecurityEvent::whereIn('session_id', $sessionIds)->count(),
                'questions' => $assessment->assessmentQuestions()->count(),
            ],
        ], $this->dataFor($tab, $assessment, $school, $distribution)));
    }

    protected function dataFor(string $tab, CbtAssessment $assessment, School $school, GradingDistributionService $distribution): array
    {
        $sessionIds = CbtSession::where('assessment_id', $assessment->id)->pluck('id');

        return match ($tab) {
            'overview' => [
                'stats' => [
                    'Attempts' => $sessionIds->count(),
                    'In progress' => CbtSession::whereIn('id', $sessionIds)->where('status', CbtSession::STATUS_IN_PROGRESS)->count(),
                    'Submitted' => CbtSession::whereIn('id', $sessionIds)->whereIn('status', [CbtSession::STATUS_SUBMITTED, CbtSession::STATUS_AUTO_SUBMITTED])->count(),
                    'Graded' => CbtSession::whereIn('id', $sessionIds)->where('status', CbtSession::STATUS_GRADED)->count(),
                    'Suspended' => CbtSession::whereIn('id', $sessionIds)->where('status', CbtSession::STATUS_SUSPENDED)->count(),
                    'High risk' => CbtSession::whereIn('id', $sessionIds)->where('risk_level', 'high')->count(),
                ],
                // eligibleTakerIds() only ever counts School\User takers
                // (class arms / explicit assignment) — meaningless for
                // an applicant-audience assessment, which is scoped by
                // applicant_school_class_id instead (see CbtAssessment::
                // isEligible()'s own applicant branch).
                'eligibleCount' => $assessment->audience === CbtAssessment::AUDIENCE_APPLICANT
                    ? ($assessment->applicant_school_class_id
                        ? \App\Models\Tenant\Applicant::where('school_class_id', $assessment->applicant_school_class_id)->count()
                        : 0)
                    : count($assessment->eligibleTakerIds()),
                'averageScore' => CbtSession::whereIn('id', $sessionIds)->whereNotNull('total_score')->avg('total_score'),
            ],
            'attempts' => [
                'sessions' => CbtSession::with('takeable')->where('assessment_id', $assessment->id)
                    ->orderByDesc('id')->paginate(30)->withQueryString(),
            ],
            'live' => [
                'sessions' => CbtSession::with('takeable')->where('assessment_id', $assessment->id)
                    ->whereIn('status', [CbtSession::STATUS_IN_PROGRESS, CbtSession::STATUS_SUSPENDED])
                    ->orderByDesc('risk_score')->get(),
            ],
            'proctors' => [
                'proctors' => CbtProctor::where(fn ($q) => $q->where('assessment_id', $assessment->id)->orWhereNull('assessment_id'))->get(),
                'staffNames' => $school->users()->pluck('name', 'users.id'),
            ],
            'graders' => [
                'graders' => CbtGrader::where(fn ($q) => $q->where('assessment_id', $assessment->id)->orWhereNull('assessment_id'))
                    ->orderBy('grade_layer')->get(),
                'staffNames' => $school->users()->pluck('name', 'users.id'),
                'config' => $distribution->configFor($assessment),
                'assignments' => CbtGraderAssignment::where('assessment_id', $assessment->id)->count(),
            ],
            'questions' => [
                'questions' => $assessment->assessmentQuestions()->with('question')->get(),
                'pools' => $assessment->pools()->with('category')->get(),
            ],
            'events' => [
                'events' => CbtSecurityEvent::with('session.takeable')->whereIn('session_id', $sessionIds)
                    ->orderByDesc('occurred_at')->paginate(50)->withQueryString(),
            ],
            'settings' => [
                'schedules' => CbtSchedule::with('takeable')->where('assessment_id', $assessment->id)->count(),
            ],
            default => [],
        };
    }

    // ── Attempt actions, shared with the console ────────────────────

    protected function ownedSession(Request $request): CbtSession
    {
        $session = CbtSession::findOrFail($request->route('sessionId'));
        abort_unless((int) $session->assessment_id === (int) $request->route('assessmentId'), 404);

        return $session;
    }

    public function suspend(Request $request, AttemptControlService $control): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $control->suspend($this->ownedSession($request), Auth::id(), $data['reason'] ?? null);

        return back()->with('status', 'Attempt suspended.');
    }

    public function reinstate(Request $request, AttemptControlService $control): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $control->reinstate($this->ownedSession($request));

        return back()->with('status', 'Attempt reinstated with the lost time given back.');
    }

    public function forceSubmit(Request $request, AttemptControlService $control, CbtSessionService $sessions): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $control->forceSubmit($this->ownedSession($request), $sessions);

        return back()->with('status', 'Attempt submitted on the taker\'s behalf.');
    }

    /** Adds minutes to one taker's clock — an access arrangement, not a correction. */
    public function grantTime(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $session = $this->ownedSession($request);
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:180']]);

        abort_unless($session->status === CbtSession::STATUS_IN_PROGRESS, 422, 'That attempt is not in progress.');
        $session->forceFill(['ends_at' => $session->ends_at->addMinutes($data['minutes'])])->save();

        return back()->with('status', $data['minutes'].' extra minute(s) granted.');
    }

    public function deleteAttempt(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $session = $this->ownedSession($request);
        // Responses, events, snapshots and chat all cascade on delete
        // (see their own migrations), so this leaves nothing orphaned.
        $session->delete();

        return back()->with('status', 'Attempt deleted.');
    }

    public function approveBreak(Request $request, BreakRequestService $breaks): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $data = $request->validate(['minutes' => ['required', 'integer', 'min:1', 'max:60']]);
        $breaks->approve($this->ownedSession($request), $data['minutes']);

        return back()->with('status', 'Break approved.');
    }

    public function denyBreak(Request $request, BreakRequestService $breaks): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $breaks->deny($this->ownedSession($request));

        return back()->with('status', 'Break request denied.');
    }

    /** Dismisses one logged proctoring signal and refunds the risk points it added. */
    public function overrideEvent(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $event = CbtSecurityEvent::with('session')->findOrFail($request->route('eventId'));
        abort_if($event->overridden, 422, 'That event has already been dismissed.');

        $session = $event->session;
        $event->forceFill(['overridden' => true])->save();

        if ($session) {
            $newScore = max(0, $session->risk_score - $event->score_impact);
            $thresholds = config('skulos.cbt_risk_thresholds', ['medium' => 30, 'high' => 60]);
            $session->forceFill([
                'risk_score' => $newScore,
                'risk_level' => $newScore >= $thresholds['high'] ? 'high' : ($newScore >= $thresholds['medium'] ? 'medium' : 'low'),
                'is_flagged' => $newScore >= $thresholds['high'],
            ])->save();
        }

        return back()->with('status', 'Event dismissed and its risk points refunded.');
    }
}
