<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Maintenance\BackupRetentionService;
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

    public function edit(Request $request, RetentionService $retention, BackupRetentionService $backupRetention): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.view');
        $school = $this->school($request);

        return view('school.settings-retention', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'settings' => $retention->schoolSettings(),
            'backupSettings' => $backupRetention->schoolSettings(),
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

    /**
     * A separate toggle/save action from update() above — backup
     * retention (2026-08-12) is a genuinely distinct concern from
     * notification/log retention, kept in its own Setting key
     * (BackupRetentionService::KEY) so turning one on/off never
     * disturbs the other.
     */
    public function updateBackups(Request $request, BackupRetentionService $backupRetention): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');

        $useOwn = $request->boolean('use_own_backup');
        if (! $useOwn) {
            $backupRetention->saveSchoolSettings([]);

            return back()->with('status', 'Now using the platform default backup retention.');
        }

        $data = $request->validate([
            'keep_count' => ['required', 'integer', 'min:1', 'max:365'],
            'keep_days' => ['required', 'integer', 'min:1', 'max:3650'],
            'prune_manual' => ['sometimes', 'boolean'],
        ]);
        $data['prune_manual'] = $request->boolean('prune_manual');

        $backupRetention->saveSchoolSettings($data);

        return back()->with('status', 'Backup retention settings saved.');
    }
}
