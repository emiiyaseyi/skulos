<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtFaceApproval;
use App\Models\Tenant\CbtPrecheckSession;
use App\Models\Tenant\CbtSession;
use App\Services\Cbt\CbtSessionService;
use App\Services\Cbt\PrecheckWizardService;
use App\Services\Media\ImageProcessingService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

/**
 * The pre-exam wizard sat in front of CbtTakeController's timed flow.
 * See PrecheckWizardService and CbtPrecheckSession::activeSteps() for
 * how the step list is derived per assessment; this controller only
 * ever handles the current step's own input and hands off to
 * CbtSessionService::start() once every active step is done.
 */
class CbtPrecheckController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), 'assessment_hub.view'),
            403,
            'You do not have permission for this.'
        );
    }

    protected function audienceFor(Request $request): string
    {
        $role = Auth::user()->roleIn($this->school($request));

        return $role?->slug === 'student' ? CbtAssessment::AUDIENCE_STUDENT : CbtAssessment::AUDIENCE_STAFF;
    }

    public function start(Request $request, PrecheckWizardService $wizard, \App\Services\Cbt\AssessmentAvailabilityService $availability, \App\Services\Cbt\CbtSessionService $sessions): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $assessment = CbtAssessment::where('audience', $this->audienceFor($request))->findOrFail($request->route('assessmentId'));

        // One question, one place — see CbtTakeController::start().
        $reason = $availability->unavailableReason($assessment, Auth::user());
        abort_if($reason !== null, 422, $reason);

        if ($assessment->access_pin) {
            abort_unless(
                $availability->pinAccepted($assessment, $request->input('access_pin')),
                422,
                'That access PIN is not correct.'
            );
        }

        // Straight into the timed attempt when the wizard does not
        // apply: either the whole install is in light mode (no
        // proctoring at all, so there is nothing to pre-check), or
        // this taker has an access arrangement to skip it.
        if (! \App\Support\AssessmentMode::precheckRequired()
            || $availability->shouldSkipPrecheck($assessment, Auth::user())) {
            $session = $sessions->start(Auth::user(), $assessment, $request->ip(), $request->userAgent());

            return redirect()->route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $session->id]);
        }

        $precheck = $wizard->start(Auth::user(), $assessment);

        return redirect()->route('school.cbt.precheck.show', ['school' => $school->slug, 'precheckId' => $precheck->id]);
    }

    protected function ownedPrecheck(Request $request): CbtPrecheckSession
    {
        $precheck = CbtPrecheckSession::with('assessment.securityProfile')->findOrFail($request->route('precheckId'));
        abort_unless($precheck->takeable_type === Auth::user()::class && $precheck->takeable_id === Auth::id(), 403);

        return $precheck;
    }

    public function show(Request $request, PrecheckWizardService $wizard, CbtSessionService $sessions): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $precheck = $this->ownedPrecheck($request);

        if ($precheck->status === CbtPrecheckSession::STATUS_COMPLETED && $precheck->cbt_session_id) {
            return redirect()->route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $precheck->cbt_session_id]);
        }

        // A pending 'face' step may have an outstanding staff-reviewed
        // approval request (Phase 2e) — a face_capture_path is already on
        // the row from when the request was raised, so an approval needs
        // no further data, only to actually advance the step.
        $faceApproval = null;
        if ($precheck->current_step === 'face') {
            $faceApproval = CbtFaceApproval::where('precheck_session_id', $precheck->id)->latest()->first();
            if ($faceApproval?->status === CbtFaceApproval::STATUS_APPROVED) {
                $finished = $wizard->completeStep($precheck, 'face', []);
                if ($finished) {
                    $session = $sessions->start(Auth::user(), $precheck->assessment, $request->ip(), $request->userAgent());
                    $precheck->forceFill(['cbt_session_id' => $session->id])->save();

                    return redirect()->route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $session->id]);
                }

                return redirect()->route('school.cbt.precheck.show', ['school' => $school->slug, 'precheckId' => $precheck->id]);
            }
        }

        $steps = $precheck->activeSteps();
        $stepIndex = array_search($precheck->current_step, $steps, true);

        return view('school.cbt.precheck.show', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'precheck' => $precheck,
            'assessment' => $precheck->assessment,
            'profile' => $precheck->assessment->securityProfile,
            'steps' => $steps,
            'stepIndex' => $stepIndex === false ? 0 : $stepIndex,
            'stepLabels' => config('skulos.precheck_wizard_steps'),
            'baselineSentence' => config('skulos.cbt_keystroke_baseline_sentence'),
            'faceApproval' => $faceApproval,
        ]);
    }

    /** Serves the uploaded photo ID back to the SAME precheck's own 'face' step JS, for client-side face-api.js comparison. Never any other precheck's file. */
    public function photoIdImage(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request);
        $precheck = $this->ownedPrecheck($request);
        abort_unless($precheck->photo_id_path, 404);

        return Storage::disk('local')->response($precheck->photo_id_path);
    }

    /**
     * The 'face' step's own JS gives up after 3 client-side attempts
     * below the match threshold (matches the reference repo's own
     * behaviour) and posts here instead of the normal advance() — this
     * does NOT advance the wizard; a member of staff must approve first
     * (CbtFaceApprovalController::decide()), at which point show() above
     * advances it automatically on the taker's next visit to this page.
     */
    public function requestFaceReview(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $precheck = $this->ownedPrecheck($request);
        abort_unless($precheck->current_step === 'face', 422);

        $data = $request->validate([
            'face_capture' => ['required', 'string', 'starts_with:data:image/'],
            'match_score' => ['required', 'numeric', 'min:0', 'max:1'],
        ]);

        [, $encoded] = explode(',', $data['face_capture'], 2) + [null, null];
        $bytes = $encoded !== null ? base64_decode($encoded, true) : false;
        abort_if($bytes === false, 422, 'Invalid face capture.');
        $path = "assessment/{$school->slug}/precheck/{$precheck->id}/face-".Str::random(16).'.jpg';
        Storage::disk('local')->put($path, $bytes);
        $precheck->forceFill(['face_capture_path' => $path])->save();

        $approval = CbtFaceApproval::where('precheck_session_id', $precheck->id)
            ->where('status', CbtFaceApproval::STATUS_PENDING)->first();
        if ($approval) {
            $approval->forceFill(['match_score' => $data['match_score'], 'attempts' => $approval->attempts + 1])->save();
        } else {
            CbtFaceApproval::create(['precheck_session_id' => $precheck->id, 'match_score' => $data['match_score'], 'attempts' => 1]);
        }

        return redirect()->route('school.cbt.precheck.show', ['school' => $school->slug, 'precheckId' => $precheck->id]);
    }

    public function advance(Request $request, PrecheckWizardService $wizard, CbtSessionService $sessions, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $precheck = $this->ownedPrecheck($request);
        $step = $precheck->current_step;

        $stepData = $this->captureStepData($request, $step, $precheck, $images);

        $finished = $wizard->completeStep($precheck, $step, $stepData);

        if (! $finished) {
            return redirect()->route('school.cbt.precheck.show', ['school' => $school->slug, 'precheckId' => $precheck->id]);
        }

        $session = $sessions->start(Auth::user(), $precheck->assessment, $request->ip(), $request->userAgent());
        $precheck->forceFill(['cbt_session_id' => $session->id])->save();

        return redirect()->route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $session->id]);
    }

    /** Validates + persists whatever the given step captures; every other step is a plain confirmation checkbox. */
    protected function captureStepData(Request $request, string $step, CbtPrecheckSession $precheck, ImageProcessingService $images): array
    {
        $school = $this->school($request);

        return match ($step) {
            'photoid' => (function () use ($request, $images, $school, $precheck) {
                $request->validate(['photo_id' => ['required', 'file', 'mimes:jpg,jpeg,png,webp', 'max:'.config('skulos.upload_limits.cbt_photo_id_kb')]]);
                $path = $images->toWebp($request->file('photo_id'), "assessment/{$school->slug}/precheck/{$precheck->id}", disk: 'local');

                return ['photo_id_path' => $path];
            })(),
            'face' => (function () use ($request, $school, $precheck) {
                // The normal (auto-pass) path — only reachable once the
                // client-side match already crossed the 50% threshold
                // (matches the reference repo's own pct >= 50 gate). A
                // client stuck below threshold uses requestFaceReview()
                // instead, never this action.
                $request->validate([
                    'face_capture' => ['required', 'string', 'starts_with:data:image/'],
                    'match_score' => ['required', 'numeric', 'min:0.5', 'max:1'],
                ]);
                [, $encoded] = explode(',', $request->input('face_capture'), 2) + [null, null];
                abort_if($encoded === null, 422, 'Invalid face capture.');
                $bytes = base64_decode($encoded, true);
                abort_if($bytes === false, 422, 'Invalid face capture.');
                $path = "assessment/{$school->slug}/precheck/{$precheck->id}/face-".Str::random(16).'.jpg';
                Storage::disk('local')->put($path, $bytes);

                return ['face_capture_path' => $path];
            })(),
            'environment' => (function () use ($request, $school, $precheck) {
                $request->validate(['environment_video' => ['required', 'file', 'mimetypes:video/webm,video/mp4', 'max:'.config('skulos.upload_limits.cbt_environment_video_kb')]]);
                $path = $request->file('environment_video')->store("assessment/{$school->slug}/precheck/{$precheck->id}", 'local');

                return ['environment_video_path' => $path];
            })(),
            'keystroke' => (function () use ($request) {
                $request->validate(['typed_text' => ['required', 'string']]);
                $baseline = config('skulos.cbt_keystroke_baseline_sentence');
                if (trim(strtolower($request->input('typed_text'))) !== trim(strtolower($baseline))) {
                    throw \Illuminate\Validation\ValidationException::withMessages([
                        'typed_text' => 'The typed sentence did not match. Please try again.',
                    ]);
                }

                return [];
            })(),
            'camera', 'audio', 'screenshare', 'monitor', 'fullscreen' => (function () use ($request, $step) {
                $request->validate(["{$step}_ok" => ['required', 'accepted']]);

                return [];
            })(),
            'closetabs' => (function () use ($request) {
                $request->validate(['confirm' => ['required', 'accepted']]);

                return [];
            })(),
            'privacy' => (function () use ($request) {
                $request->validate(['agree' => ['required', 'accepted']]);

                return [];
            })(),
            'ready' => (function () use ($request) {
                $request->validate(['confirm_ready' => ['required', 'accepted']]);

                return [];
            })(),
            default => [],
        };
    }
}
