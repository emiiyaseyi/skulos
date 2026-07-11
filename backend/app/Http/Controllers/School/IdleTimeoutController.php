<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Rbac\Rbac;
use App\Services\Security\IdleTimeoutService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A school's own idle sign out timeout (user request 2026-07-11),
 * optional, if left off the platform default applies instead.
 */
class IdleTimeoutController extends Controller
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

    public function edit(Request $request, IdleTimeoutService $idle): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.view');
        $school = $this->school($request);

        return view('school.settings-idle-timeout', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'settings' => $idle->schoolSettings(),
        ]);
    }

    public function update(Request $request, IdleTimeoutService $idle): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');

        $useOwn = $request->boolean('use_own');
        if (! $useOwn) {
            $idle->saveSchoolSettings([]);

            return back()->with('status', 'Now using the platform default idle timeout.');
        }

        $data = $request->validate([
            'idle_seconds' => ['required', 'integer', 'min:10', 'max:86400'],
            'warning_seconds' => ['required', 'integer', 'min:5', 'max:600'],
        ]);

        $idle->saveSchoolSettings([
            'enabled' => $request->boolean('enabled'),
            'idle_seconds' => $data['idle_seconds'],
            'warning_seconds' => $data['warning_seconds'],
        ]);

        return back()->with('status', 'Idle timeout settings saved.');
    }
}
