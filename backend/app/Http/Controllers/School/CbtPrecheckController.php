<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtPaper;
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
 * SKULOS Assessment Hub, Phase 2d: the pre-exam wizard sat in front of
 * CbtTakeController's timed flow. See PrecheckWizardService and
 * CbtPrecheckSession::activeSteps() for how the step list is derived
 * per paper; this controller only ever handles the current step's own
 * input and hands off to CbtSessionService::start() once every active
 * step is done.
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

        return $role?->slug === 'student' ? CbtPaper::AUDIENCE_STUDENT : CbtPaper::AUDIENCE_STAFF;
    }

    public function start(Request $request, PrecheckWizardService $wizard): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $paper = CbtPaper::where('is_active', true)->where('audience', $this->audienceFor($request))->findOrFail($request->route('paperId'));

        $alreadyAttempted = CbtSession::where('paper_id', $paper->id)
            ->where('takeable_type', Auth::user()::class)->where('takeable_id', Auth::id())
            ->whereIn('status', [CbtSession::STATUS_SUBMITTED, CbtSession::STATUS_AUTO_SUBMITTED, CbtSession::STATUS_GRADED])
            ->exists();
        abort_if($alreadyAttempted, 422, 'You have already completed this assessment.');

        $precheck = $wizard->start(Auth::user(), $paper);

        return redirect()->route('school.cbt.precheck.show', ['school' => $school->slug, 'precheckId' => $precheck->id]);
    }

    protected function ownedPrecheck(Request $request): CbtPrecheckSession
    {
        $precheck = CbtPrecheckSession::with('paper.securityProfile')->findOrFail($request->route('precheckId'));
        abort_unless($precheck->takeable_type === Auth::user()::class && $precheck->takeable_id === Auth::id(), 403);

        return $precheck;
    }

    public function show(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $precheck = $this->ownedPrecheck($request);

        if ($precheck->status === CbtPrecheckSession::STATUS_COMPLETED && $precheck->cbt_session_id) {
            return redirect()->route('school.cbt.take.session', ['school' => $school->slug, 'sessionId' => $precheck->cbt_session_id]);
        }

        $steps = $precheck->activeSteps();
        $stepIndex = array_search($precheck->current_step, $steps, true);

        return view('school.cbt.precheck.show', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'precheck' => $precheck,
            'paper' => $precheck->paper,
            'profile' => $precheck->paper->securityProfile,
            'steps' => $steps,
            'stepIndex' => $stepIndex === false ? 0 : $stepIndex,
            'stepLabels' => config('skulos.precheck_wizard_steps'),
            'baselineSentence' => config('skulos.cbt_keystroke_baseline_sentence'),
        ]);
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

        $session = $sessions->start(Auth::user(), $precheck->paper, $request->ip(), $request->userAgent());
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
                $request->validate(['face_capture' => ['required', 'string', 'starts_with:data:image/']]);
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
