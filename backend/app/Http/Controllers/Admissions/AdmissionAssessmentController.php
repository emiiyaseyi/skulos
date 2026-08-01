<?php

namespace App\Http\Controllers\Admissions;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtSession;
use App\Services\Cbt\AssessmentAvailabilityService;
use App\Services\Cbt\BreakRequestService;
use App\Services\Cbt\CbtSessionService;
use App\Services\Cbt\CbtSnapshotService;
use App\Services\Cbt\RiskScoringService;
use App\Services\Cbt\ScoreReportingService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The applicant-facing side of the Assessment Hub — an Admission
 * Assessment (CbtAssessment::AUDIENCE_APPLICANT). Deliberately a
 * separate controller from School\CbtTakeController rather than a
 * guard-parametrized version of it: an Applicant authenticates on its
 * own 'applicant' guard (see Applicant's own docblock for why it is
 * never a School\User at all), has no RBAC role to check
 * (Rbac::check() is School\User-shaped throughout), and is reached
 * from the Admissions portal, not the school-staff one.
 *
 * Reuses the EXACT same views (resources/views/school/cbt/take/*) and
 * the same underlying services (CbtSessionService, QuestionSetBuilder,
 * AssessmentAvailabilityService, ScoreReportingService — all already
 * written against a generic takeable Model, never School\User
 * specifically) — those views take an explicit $routes array and a
 * $portal variable rather than hardcoding school.cbt.take.* route
 * names, exactly so a second controller like this one can render them
 * unchanged. See CbtTakeController::take()'s own comment on $routes.
 *
 * Proctoring is not wired here: an admission assessment always runs in
 * light mode (App\Support\AssessmentMode::proctoringEnabled() false)
 * for now, per the "no proctor/invigilation for now" scope cut — a
 * proctored admission assessment would need this controller to also
 * drive the pre-exam wizard (CbtPrecheckWizardService), which is
 * explicitly deferred rather than half-built here.
 */
class AdmissionAssessmentController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function applicant(): Applicant
    {
        return Auth::guard('applicant')->user();
    }

    public function available(Request $request, AssessmentAvailabilityService $availability): \Illuminate\View\View
    {
        $school = $this->school($request);
        $applicant = $this->applicant();

        $assessments = CbtAssessment::where('audience', CbtAssessment::AUDIENCE_APPLICANT)
            ->where('is_active', true)->orderByDesc('id')->get()
            ->filter(fn (CbtAssessment $assessment) => $assessment->isEligible($applicant))
            ->values();
        $sessions = CbtSession::where('takeable_type', Applicant::class)->where('takeable_id', $applicant->id)
            ->get()->keyBy('assessment_id');

        $rows = $assessments->map(fn (CbtAssessment $assessment) => [
            'assessment' => $assessment,
            'session' => $session = $sessions->get($assessment->id),
            'startUrl' => route('school.admissions.assessment.start', ['school' => $school->slug, 'assessmentId' => $assessment->id]),
            'resumeUrl' => $session ? route('school.admissions.assessment.session', ['school' => $school->slug, 'sessionId' => $session->id]) : null,
            'resultUrl' => $session ? route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id]) : null,
            'scoreVisible' => $session ? $availability->resultsVisible($assessment, $session) : false,
        ]);

        return view('school.cbt.take.available', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'portal' => 'admissions',
            'rows' => $rows,
        ]);
    }

    public function start(Request $request, CbtSessionService $sessions, AssessmentAvailabilityService $availability): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $applicant = $this->applicant();
        $assessment = CbtAssessment::where('audience', CbtAssessment::AUDIENCE_APPLICANT)
            ->findOrFail($request->route('assessmentId'));

        abort_unless($assessment->isEligible($applicant), 403, 'This assessment is not open to your application.');

        $reason = $availability->unavailableReason($assessment, $applicant);
        abort_if($reason !== null, 422, $reason);

        $resuming = CbtSession::where('assessment_id', $assessment->id)
            ->where('takeable_type', Applicant::class)->where('takeable_id', $applicant->id)
            ->where('status', CbtSession::STATUS_IN_PROGRESS)->exists();

        if (! $resuming && $assessment->access_pin) {
            abort_unless(
                $availability->pinAccepted($assessment, $request->input('access_pin')),
                422,
                'That access PIN is not correct.'
            );
        }

        // Proctoring is never active for an admission assessment yet
        // (see this class's own docblock) — straight into the timed
        // attempt, same as CbtPrecheckController::start() does in light
        // mode for a School\User.
        $session = $sessions->start($applicant, $assessment, $request->ip(), $request->userAgent());

        return redirect()->route('school.admissions.assessment.session', ['school' => $school->slug, 'sessionId' => $session->id]);
    }

    protected function ownedSession(Request $request): CbtSession
    {
        $applicant = $this->applicant();
        $session = CbtSession::with(['assessment.securityProfile', 'assessment.assessmentQuestions.question.answers'])
            ->findOrFail($request->route('sessionId'));
        abort_unless($session->takeable_type === Applicant::class && $session->takeable_id === $applicant->id, 403);

        return $session;
    }

    protected function routesFor(School $school, CbtSession $session): array
    {
        return [
            'answer' => route('school.admissions.assessment.answer', ['school' => $school->slug, 'sessionId' => $session->id]),
            'submit' => route('school.admissions.assessment.submit', ['school' => $school->slug, 'sessionId' => $session->id]),
            'event' => route('school.admissions.assessment.event', ['school' => $school->slug, 'sessionId' => $session->id]),
            'break' => route('school.admissions.assessment.break-request', ['school' => $school->slug, 'sessionId' => $session->id]),
            'available' => route('school.admissions.assessment.available', ['school' => $school->slug]),
        ];
    }

    public function take(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $session = $this->ownedSession($request);

        if ($session->status !== CbtSession::STATUS_IN_PROGRESS) {
            return redirect()->route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id]);
        }

        $existingAnswers = $session->responses()->pluck('answer', 'question_id');
        $builder = app(\App\Services\Cbt\QuestionSetBuilder::class);
        $builder->buildFor($session);
        $sectionService = app(\App\Services\Cbt\ExamSectionService::class);

        return view('school.cbt.take.session', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'portal' => 'admissions',
            'routes' => $this->routesFor($school, $session),
            'session' => $session,
            'assessment' => $session->assessment,
            'existingAnswers' => $existingAnswers,
            'given' => $builder->questionsFor($session),
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

    /** Kept for parity with CbtTakeController::reportEvent() should proctoring ever be switched on for admission assessments — inert while it isn't, since the take screen only ever reports an event when its security profile flags require it. */
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

    public function requestBreak(Request $request, BreakRequestService $breaks): \Illuminate\Http\JsonResponse
    {
        $session = $this->ownedSession($request);
        $data = $request->validate(['reason' => ['required', 'string', 'max:255']]);

        $breaks->request($session, $data['reason']);

        return response()->json(['requested' => true]);
    }

    public function submit(Request $request, CbtSessionService $sessions, ScoreReportingService $reporting): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $session = $this->ownedSession($request);

        $sessions->submit($session);
        $this->maybeAdvancePipelineOnPass($session->fresh(), $reporting);

        return redirect()->route('school.admissions.assessment.result', ['school' => $school->slug, 'sessionId' => $session->id])
            ->with('status', 'Submitted.');
    }

    public function result(Request $request, AssessmentAvailabilityService $availability, ScoreReportingService $reporting): \Illuminate\View\View
    {
        $school = $this->school($request);
        $session = $this->ownedSession($request);
        abort_if($session->status === CbtSession::STATUS_IN_PROGRESS, 422, 'This attempt is still in progress.');

        $this->maybeAdvancePipelineOnPass($session, $reporting);

        $assessment = $session->assessment;
        $resultsVisible = $session->status === CbtSession::STATUS_GRADED
            && $availability->resultsVisible($assessment, $session);
        $score = $session->total_score !== null ? (float) $session->total_score : null;
        $passed = $resultsVisible ? $reporting->passed($assessment, $score) : null;

        return view('school.cbt.take.result', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'portal' => 'admissions',
            'routes' => ['available' => route('school.admissions.assessment.available', ['school' => $school->slug])],
            'session' => $session,
            'resultsVisible' => $resultsVisible,
            'passed' => $passed,
        ]);
    }

    /**
     * The actual "wiring into the pipeline stage" (user directive
     * 2026-08-01): once an admission assessment is fully graded and
     * the applicant cleared its pass mark, they move on to whatever
     * stage comes after "Admission Assessment" in THEIR OWN school's
     * current pipeline. Deliberately independent of results_mode —
     * whether the applicant is yet ALLOWED TO SEE their score is a
     * separate, admin-configured release policy (see resultsVisible()),
     * but the pipeline itself is an internal admin-facing state that
     * shouldn't sit blocked on that same toggle. Safe to call from both
     * submit() and result(): advanceStage() is a no-op past the final
     * stage, and an applicant already moved past "Admission Assessment"
     * simply won't match the label check below on a later call.
     */
    protected function maybeAdvancePipelineOnPass(CbtSession $session, ScoreReportingService $reporting): void
    {
        if ($session->status !== CbtSession::STATUS_GRADED) {
            return;
        }

        $score = $session->total_score !== null ? (float) $session->total_score : null;
        if ($reporting->passed($session->assessment, $score) !== true) {
            return;
        }

        $applicant = $this->applicant();
        $configuredLabel = config('skulos.admission_pipeline_stages.admission_assessment');
        if ($applicant->currentStageLabel() === $configuredLabel) {
            $applicant->advanceStage();
        }
    }
}
