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
        ]);
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
        ]);

        foreach (['require_fullscreen', 'block_copy_paste', 'block_right_click', 'require_camera_check', 'require_object_detection'] as $flag) {
            $data[$flag] = $request->boolean($flag);
        }

        $profile->update($data);

        return back()->with('status', "{$profile->name} profile updated.");
    }
}
