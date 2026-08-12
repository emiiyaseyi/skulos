<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtSecurityProfile;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * SKULOS Assessment Hub, Phase 2a: tunes the four fixed security tiers
 * (Basic/Medium/High/Maximum) a school's papers can use. The tier set
 * itself is fixed by the original spec — no add/remove here, only each
 * tier's own flags are editable.
 */
class CbtSecurityProfileController extends Controller
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
        // assessment_hub.export, not .create (corrected 2026-08-05, user
        // directive: "teachers should not be having access to the
        // security profiles"). Security profiles are a school-wide
        // proctoring policy, not a per-assessment setting a class/subject
        // teacher builds — .create/.update were broadened to those roles
        // 2026-08-02 for their own scoped assessment building only, and
        // this screen shared the same gate as an unintended side effect.
        // .export is never granted to a scoped teacher role, the same
        // "blanket access" signal used elsewhere in this module.
        $this->authorizeAction($request, 'assessment_hub.export');
        $school = $this->school($request);
        CbtSecurityProfile::ensureSeeded();

        return view('school.cbt.security-profiles.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'profiles' => CbtSecurityProfile::orderBy('id')->get(),
            'eventTypeLabels' => [
                'tab_switch' => 'Switching tabs',
                'fullscreen_exit' => 'Leaving fullscreen',
                'copy_attempt' => 'Copying text',
                'paste_attempt' => 'Pasting text',
                'right_click' => 'Right-clicking',
                'window_blur' => 'Clicking outside the window',
                'face_not_detected' => 'Face not detected',
                'face_mismatch' => 'Face does not match',
                'object_detected' => 'Phone/second person detected',
                'noise_threshold' => 'Excess background noise',
            ],
        ]);
    }

    /**
     * The event types a profile's own risk weights cover — the same
     * set RiskScoringService actually calls recordEvent() with, kept in
     * one place so the form/validation/view never drift from it.
     *
     * @return array<int, string>
     */
    public static function eventTypes(): array
    {
        return [
            'tab_switch', 'fullscreen_exit', 'copy_attempt', 'paste_attempt', 'right_click',
            'window_blur', 'face_not_detected', 'face_mismatch', 'object_detected', 'noise_threshold',
        ];
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.export');
        $profile = CbtSecurityProfile::findOrFail($request->route('profileId'));

        $data = $request->validate([
            'require_fullscreen' => ['sometimes', 'boolean'],
            'block_copy_paste' => ['sometimes', 'boolean'],
            'block_right_click' => ['sometimes', 'boolean'],
            'tab_switch_action' => ['required', Rule::in(['none', 'warn', 'lock'])],
            'tab_switch_limit' => ['nullable', 'integer', 'min:1'],
            'require_camera_check' => ['sometimes', 'boolean'],
            'require_object_detection' => ['sometimes', 'boolean'],
            // The 6 identity/wizard flags a 2026-08-01 migration added to
            // the model/DB but this form never actually exposed (a real
            // bug, found investigating item 18) — now genuinely editable.
            'require_audio_check' => ['sometimes', 'boolean'],
            'require_photo_id' => ['sometimes', 'boolean'],
            'require_face_match' => ['sometimes', 'boolean'],
            'require_screen_share' => ['sometimes', 'boolean'],
            'require_environment_scan' => ['sometimes', 'boolean'],
            'snapshot_interval_seconds' => ['nullable', 'integer', 'min:1'],
            // Real per-profile risk governance (item 18, in line with
            // the LearnGuard reference plugin's own settings catalog).
            'event_scores' => ['required', 'array'],
            'event_scores.*' => ['required', 'integer', 'min:0'],
            'risk_medium_threshold' => ['required', 'integer', 'min:0'],
            'risk_high_threshold' => ['required', 'integer', 'min:0'],
            'autosuspend_score' => ['nullable', 'integer', 'min:0'],
            'break_min_minutes' => ['nullable', 'integer', 'min:0'],
            'break_max_minutes' => ['nullable', 'integer', 'min:0'],
        ]);

        foreach ([
            'require_fullscreen', 'block_copy_paste', 'block_right_click', 'require_camera_check', 'require_object_detection',
            'require_audio_check', 'require_photo_id', 'require_face_match', 'require_screen_share', 'require_environment_scan',
        ] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        // Only the recognized event types are kept, regardless of what
        // a crafted request might add — the form only ever renders
        // eventTypes() as inputs, this just guards the write side too.
        $data['event_scores'] = array_intersect_key($data['event_scores'], array_flip(self::eventTypes()));

        $profile->update($data);

        return back()->with('status', "{$profile->name} profile updated.");
    }
}
