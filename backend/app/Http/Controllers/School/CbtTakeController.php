<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtPaper;
use App\Models\Tenant\CbtSession;
use App\Services\Cbt\CbtSessionService;
use App\Services\Cbt\RiskScoringService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * SKULOS Assessment Hub, Phase 2b: the taker-facing side (a School\User —
 * student or staff, both reached here through the normal 'web' guard,
 * the app has no separate student/staff nav file — see CbtSession's own
 * docblock for why `takeable` is polymorphic and how an Applicant would
 * plug into this same controller shape later, unbuilt this phase).
 */
class CbtTakeController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function audienceFor(Request $request): string
    {
        $role = Auth::user()->roleIn($this->school($request));

        return $role?->slug === 'student' ? CbtPaper::AUDIENCE_STUDENT : CbtPaper::AUDIENCE_STAFF;
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }

    public function available(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assessment_hub.view');
        $school = $this->school($request);
        $taker = Auth::user();
        $audience = $this->audienceFor($request);

        $papers = CbtPaper::where('audience', $audience)->where('is_active', true)->orderByDesc('id')->get();
        $sessions = CbtSession::where('takeable_type', $taker::class)->where('takeable_id', $taker->id)
            ->get()->keyBy('paper_id');

        return view('school.cbt.take.available', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'papers' => $papers,
            'sessions' => $sessions,
        ]);
    }

    public function start(Request $request, CbtSessionService $sessions): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.view');
        $school = $this->school($request);
        $paper = CbtPaper::where('is_active', true)->where('audience', $this->audienceFor($request))->findOrFail($request->route('paperId'));

        $existingFinished = CbtSession::where('paper_id', $paper->id)
            ->where('takeable_type', Auth::user()::class)->where('takeable_id', Auth::id())
            ->whereIn('status', [CbtSession::STATUS_SUBMITTED, CbtSession::STATUS_AUTO_SUBMITTED, CbtSession::STATUS_GRADED])
            ->exists();
        abort_if($existingFinished, 422, 'You have already completed this assessment.');

        $session = $sessions->start(Auth::user(), $paper, $request->ip(), $request->userAgent());

        return redirect()->route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $session->id]);
    }

    protected function ownedSession(Request $request): CbtSession
    {
        $session = CbtSession::with(['paper.securityProfile', 'paper.paperQuestions.question'])->findOrFail($request->route('sessionId'));
        abort_unless($session->takeable_type === Auth::user()::class && $session->takeable_id === Auth::id(), 403);

        return $session;
    }

    public function take(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.view');
        $school = $this->school($request);
        $session = $this->ownedSession($request);

        if ($session->status !== CbtSession::STATUS_IN_PROGRESS) {
            return redirect()->route('school.cbt.take.result', ['school' => $school->slug, 'sessionId' => $session->id]);
        }

        $existingAnswers = $session->responses()->pluck('answer', 'question_id');

        return view('school.cbt.take.session', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'session' => $session,
            'paper' => $session->paper,
            'existingAnswers' => $existingAnswers,
        ]);
    }

    public function saveAnswer(Request $request, CbtSessionService $sessions): \Illuminate\Http\JsonResponse
    {
        $session = $this->ownedSession($request);
        abort_unless($session->status === CbtSession::STATUS_IN_PROGRESS, 422);

        $data = $request->validate([
            'question_id' => ['required', 'integer', 'exists:tenant.cbt_questions,id'],
            'answer' => ['nullable'],
        ]);

        $sessions->saveAnswer($session, $data['question_id'], $data['answer'] ?? null);

        return response()->json(['saved' => true]);
    }

    /**
     * The take screen's own JS reports raw proctoring signals here (tab
     * switch, fullscreen exit, copy/paste, right-click — Phase 2c; AI
     * checks join in Phase 2e). A `tab_switch_action=lock` profile that
     * has crossed its own tab_switch_limit auto-submits the session as a
     * direct side effect of the event that pushed it over, matching
     * LearnGuard's own "violations_suspended" behaviour.
     */
    public function reportEvent(Request $request, RiskScoringService $risk, CbtSessionService $sessions): \Illuminate\Http\JsonResponse
    {
        $session = $this->ownedSession($request);
        abort_unless($session->status === CbtSession::STATUS_IN_PROGRESS, 422);

        $data = $request->validate([
            'type' => ['required', Rule::in(['tab_switch', 'fullscreen_exit', 'copy_attempt', 'paste_attempt', 'right_click', 'window_blur'])],
        ]);

        $risk->recordEvent($session, $data['type']);
        $session->refresh();

        $profile = $session->paper->securityProfile;
        $autoSubmitted = false;
        if ($data['type'] === 'tab_switch' && $profile->tab_switch_action === 'lock'
            && $profile->tab_switch_limit && $session->tab_switches >= $profile->tab_switch_limit) {
            $sessions->submit($session, CbtSession::STATUS_AUTO_SUBMITTED);
            $autoSubmitted = true;
        }

        return response()->json(['recorded' => true, 'auto_submitted' => $autoSubmitted]);
    }

    public function submit(Request $request, CbtSessionService $sessions): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $session = $this->ownedSession($request);

        $sessions->submit($session);

        return redirect()->route('school.cbt.take.result', ['school' => $school->slug, 'sessionId' => $session->id])
            ->with('status', 'Submitted.');
    }

    public function result(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);
        $session = $this->ownedSession($request);
        abort_if($session->status === CbtSession::STATUS_IN_PROGRESS, 422, 'This attempt is still in progress.');

        return view('school.cbt.take.result', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'session' => $session,
        ]);
    }
}
