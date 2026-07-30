<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\School;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Backup and restore for one school (user request 2026-07-09): the school
 * admin can back up on demand, set a default schedule, download any past
 * backup, or restore from one if something goes wrong. A platform admin
 * can do the same from the admin side (Admin\BackupAdminController) using
 * the same BackupService.
 *
 * The {backupId} route param is read via $request->route() rather than a
 * typed method parameter: these routes sit under a domain wildcard
 * ({school}.<domain>), and Laravel resolves untyped scalar controller
 * parameters positionally against ALL route parameters including the
 * domain one, not by name.
 */
class BackupController extends Controller
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
        $this->authorizeAction($request, 'school_info.view');
        $school = $this->school($request);

        return view('school.backups', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'backups' => Backup::where('school_id', $school->id)->orderByDesc('created_at')->get(),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'school_info.manage_settings'),
        ]);
    }

    public function store(Request $request, BackupService $backups): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $backups->create($this->school($request), Backup::TYPE_MANUAL, Auth::user());

        return back()->with('status', 'Backup created.');
    }

    public function updateSchedule(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $data = $request->validate([
            'backup_frequency' => ['required', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'backup_time' => ['nullable', 'date_format:H:i'],
        ]);

        $this->school($request)->update([
            'backup_frequency' => $data['backup_frequency'],
            'backup_time' => $data['backup_frequency'] === 'none' ? null : ($data['backup_time'] ?? '02:00'),
        ]);

        return back()->with('status', 'Backup schedule updated.');
    }

    public function download(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $backup = Backup::where('school_id', $this->school($request)->id)->findOrFail($request->route('backupId'));
        abort_unless($backup->exists(), 404);

        return Storage::disk($backup->disk)->download($backup->file_path, basename($backup->file_path));
    }

    public function restore(Request $request, BackupService $backups): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $backup = Backup::where('school_id', $this->school($request)->id)->findOrFail($request->route('backupId'));

        try {
            $backups->restore($backup);
        } catch (\RuntimeException $e) {
            return back()->with('error', 'Restore failed, your current data was not touched: '.$e->getMessage());
        }

        return back()->with('status', 'Restored from the backup taken on '.$backup->created_at->toDayDateTimeString().'.');
    }
}
