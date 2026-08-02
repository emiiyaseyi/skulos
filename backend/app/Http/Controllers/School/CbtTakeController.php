<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtSession;
use App\Services\Cbt\AssessmentAvailabilityService;
use App\Services\Cbt\BreakRequestService;
use App\Services\Cbt\CbtSessionService;
use App\Services\Cbt\CbtSnapshotService;
use App\Services\Cbt\RiskScoringService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The taker-facing side (a School\User — student or staff, both
 * reached here through the normal 'web' guard, the app has no separate
 * student/staff nav file — see CbtSession's own docblock for why
 * `takeable` is polymorphic and how an Applicant would plug into this
 * same controller shape later, unbuilt so far).
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

        return $role?->slug === 'student' ? CbtAssessment::AUDIENCE_STUDENT : CbtAssessment::AUDIENCE_STAFF;
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }

    public function available(Request $request, AssessmentAvailabilityService $availability): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assessment_hub.view');
        $school = $this->school($request);
        $taker = Auth::user();
        $audience = $this->audienceFor($request);

        $assessments = CbtAssessment::with(['classArms', 'takers', 'pools.category', 'assessmentQuestions'])
            ->where('audience', $audience)->where('is_active', true)->orderByDesc('id')->get()
            ->filter(fn (CbtAssessment $assessment) => $assessment->isEligible($taker))
            ->values();
        $sessions = CbtSession::where('takeable_type', $taker::class)->where('takeable_id', $taker->id)
            ->get()->keyBy('assessment_id');

        // The row shape (assessment/session/urls/scoreVisible) is
        // shared with AdmissionAssessmentController's own available(),
        // so this same view renders for both a school taker and an
        // applicant — see that controller's own docblock.
        $rows = $assessments->map(fn (CbtAssessment $assessment) => [
            'assessment' => $assessment,
            'session' => $session = $sessions->get($assessment->id),
            'startUrl' => route('school.cbt.precheck.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]),
            'resumeUrl' => $session ? route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $session->id]) : null,
            'resultUrl' => $session ? route('school.cbt.take.result', ['school' => $school->slug, 'sessionId' => $session->id]) : null,
            'scoreVisible' => $session ? $availability->resultsVisible($assessment, $session) : false,
        ]);

        return view('school.cbt.take.available', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'portal' => 'school',
            'rows' => $rows,
        ]);
    }

    public function start(Request $request, CbtSessionService $sessions, AssessmentAvailabilityService $availability): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.view');
        $school = $this->school($request);
        $assessment = CbtAssessment::where('audience', $this->audienceFor($request))->findOrFail($request->route('assessmentId'));

        // Eligibility, window, and how many attempts are left are all
        // one question, answered in one place — a blanket "already
        // completed" check here would ignore an assessment that
        // deliberately allows several attempts, or a taker granted an
        // extra one.
        $reason = $availability->unavailableReason($assessment, Auth::user());
        abort_if($reason !== null, 422, $reason);

        // An in-progress attempt is resumed rather than blocked.
        $resuming = CbtSession::where('assessment_id', $assessment->id)
            ->where('takeable_type', Auth::user()::class)->where('takeable_id', Auth::id())
            ->where('status', CbtSession::STATUS_IN_PROGRESS)->exists();

        if (! $resuming && $assessment->access_pin) {
            abort_unless(
                $availability->pinAccepted($assessment, $request->input('access_pin')),
                422,
                'That access PIN is not correct.'
            );
        }

        $session = $sessions->start(Auth::user(), $assessment, $request->ip(), $request->userAgent());

        return redirect()->route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $session->id]);
    }

    protected function ownedSession(Request $request): CbtSession
    {
        $session = CbtSession::with(['assessment.securityProfile', 'assessment.assessmentQuestions.question.answers'])->findOrFail($request->route('sessionId'));
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
        $builder = app(\App\Services\Cbt\QuestionSetBuilder::class);
        $builder->buildFor($session);
        $sectionService = app(\App\Services\Cbt\ExamSectionService::class);

        return view('school.cbt.take.session', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'portal' => 'school',
            // Explicit URLs, not route names, so this same view (and
            // its _exam-scripts partial, which inherits this scope
            // automatically via Blade's @include) renders identically
            // whether a School\User reached it through this school-
            // portal controller, or an Applicant reached it through
            // AdmissionAssessmentController's own parallel routes.
            'routes' => [
                'answer' => route('school.cbt.take.answer', ['school' => $school->slug, 'sessionId' => $session->id]),
                'submit' => route('school.cbt.take.submit', ['school' => $school->slug, 'sessionId' => $session->id]),
                'event' => route('school.cbt.take.event', ['school' => $school->slug, 'sessionId' => $session->id]),
                'break' => route('school.cbt.take.break-request', ['school' => $school->slug, 'sessionId' => $session->id]),
                'available' => route('school.cbt.take.available', ['school' => $school->slug]),
            ],
            'session' => $session,
            'assessment' => $session->assessment,
            'existingAnswers' => $existingAnswers,
            // The questions THIS taker was given, in their own order —
            // not the assessment's full list, which differs the moment
            // randomisation or shuffling is on.
            'given' => $builder->questionsFor($session),
            // Grouped into the banks (sections) the taker actually sees,
            // each with its own name and, under per_section timing, its
            // own deadline — see ExamSectionService's own docblock. Sent
            // to the take screen's JS as a plain array (not the Eloquent
            // collection sectionsFor() returns), since @json only needs
            // to serialize what the countdown/banner logic actually
            // reads: which question index starts each section, its
            // label, and its own deadline if it has one.
            'showSections' => $sectionService->hasSections($session),
            'perSectionTimer' => $sectionService->isPerSection($session),
            'sectionMeta' => $sectionService->sectionsFor($session)->map(fn ($s) => [
                'order' => $s['order'],
                'label' => $s['label'],
                'first_index' => $s['first_index'],
                'last_index' => $s['last_index'],
                'ends_at' => $s['ends_at']?->toIso8601String(),
            ])->values()->all(),
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
     * switch, fullscreen exit, copy/paste, right-click, plus the AI
     * face/object checks). A `tab_switch_action=lock` profile that has
     * crossed its own tab_switch_limit auto-submits the session as a
     * direct side effect of the event that pushed it over, matching the
     * reference repo's own "violations_suspended" behaviour.
     */
    public function reportEvent(Request $request, RiskScoringService $risk, CbtSessionService $sessions, CbtSnapshotService $snapshots): \Illuminate\Http\JsonResponse
    {
        $session = $this->ownedSession($request);
        abort_unless($session->status === CbtSession::STATUS_IN_PROGRESS, 422);

        $data = $request->validate([
            'type' => ['required', Rule::in([
                'tab_switch', 'fullscreen_exit', 'copy_attempt', 'paste_attempt', 'right_click', 'window_blur',
                'face_not_detected', 'face_mismatch', 'object_detected',
            ])],
            'meta' => ['nullable', 'array'],
            // An optional evidence capture taken alongside a concerning
            // AI signal — never required, most events (tab switch,
            // copy/paste) never carry one.
            'snapshot' => ['nullable', 'string', 'starts_with:data:image/'],
        ]);

        $risk->recordEvent($session, $data['type'], $data['meta'] ?? null);
        $session->refresh();

        if (! empty($data['snapshot']) && in_array($data['type'], ['face_not_detected', 'face_mismatch', 'object_detected'], true)) {
            $snapshots->capture($session, 'webcam', $data['snapshot'], $this->school($request)->slug);
        }

        $profile = $session->assessment->securityProfile;
        $autoSubmitted = false;
        if ($data['type'] === 'tab_switch' && $profile->tab_switch_action === 'lock'
            && $profile->tab_switch_limit && $session->tab_switches >= $profile->tab_switch_limit) {
            $sessions->submit($session, CbtSession::STATUS_AUTO_SUBMITTED);
            $autoSubmitted = true;
        }

        return response()->json(['recorded' => true, 'auto_submitted' => $autoSubmitted]);
    }

    /** A taker's own break request — staff decides from the live monitor. */
    public function requestBreak(Request $request, BreakRequestService $breaks): \Illuminate\Http\JsonResponse
    {
        $session = $this->ownedSession($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $breaks->request($session, $data['reason']);

        return response()->json(['requested' => true]);
    }

    public function submit(Request $request, CbtSessionService $sessions): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $session = $this->ownedSession($request);

        $sessions->submit($session);

        return redirect()->route('school.cbt.take.result', ['school' => $school->slug, 'sessionId' => $session->id])
            ->with('status', 'Submitted.');
    }

    public function result(Request $request, AssessmentAvailabilityService $availability, \App\Services\Cbt\ScoreReportingService $reporting): \Illuminate\View\View
    {
        $school = $this->school($request);
        $session = $this->ownedSession($request);
        abort_if($session->status === CbtSession::STATUS_IN_PROGRESS, 422, 'This attempt is still in progress.');

        $assessment = $session->assessment;
        // Was previously never checked here at all: a 'manual'/'never'/
        // 'after_close' assessment leaked the raw score to this screen
        // the moment a taker submitted, regardless of the release
        // policy the admin actually configured.
        $resultsVisible = $session->status === CbtSession::STATUS_GRADED
            && $availability->resultsVisible($assessment, $session);

        $passed = $resultsVisible ? $reporting->passed($assessment, $session->total_score !== null ? (float) $session->total_score : null) : null;

        return view('school.cbt.take.result', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'portal' => 'school',
            'routes' => ['available' => route('school.cbt.take.available', ['school' => $school->slug])],
            'session' => $session,
            'resultsVisible' => $resultsVisible,
            'passed' => $passed,
        ]);
    }
}
