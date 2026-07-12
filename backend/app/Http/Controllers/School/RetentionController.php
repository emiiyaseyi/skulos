<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Maintenance\RetentionService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A school's own retention windows (Dbase recommendation), optional, if
 * left off the platform default applies instead.
 */
class RetentionController extends Controller
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

    public function edit(Request $request, RetentionService $retention): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.view');
        $school = $this->school($request);

        return view('school.settings-retention', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'settings' => $retention->schoolSettings(),
        ]);
    }

    public function update(Request $request, RetentionService $retention): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');

        $useOwn = $request->boolean('use_own');
        if (! $useOwn) {
            $retention->saveSchoolSettings([]);

            return back()->with('status', 'Now using the platform default retention windows.');
        }

        $data = $request->validate([
            'notifications_days' => ['required', 'integer', 'min:7', 'max:3650'],
            'communication_logs_days' => ['required', 'integer', 'min:7', 'max:3650'],
        ]);

        $retention->saveSchoolSettings($data);

        return back()->with('status', 'Retention settings saved.');
    }
}
