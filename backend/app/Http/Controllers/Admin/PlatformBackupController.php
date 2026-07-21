<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformBackup;
use App\Services\PlatformBackupService;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * Platform admin screen for backups of the shared platform database
 * itself, distinct from Admin\BackupAdminController (every school's own
 * tenant database backups) — see PlatformBackup's migration docblock.
 */
class PlatformBackupController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        return view('admin.platform-backups', [
            'backups' => PlatformBackup::with('initiator')->orderByDesc('created_at')->get(),
        ]);
    }

    public function store(PlatformBackupService $backups): \Illuminate\Http\RedirectResponse
    {
        $backup = $backups->create(PlatformBackup::TYPE_MANUAL, Auth::user());

        return back()->with('status', "Platform database backed up ({$backup->humanSize()}).");
    }

    public function download(string $backupId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $backup = PlatformBackup::findOrFail($backupId);
        abort_unless($backup->exists(), 404);

        return Storage::disk($backup->disk)->download($backup->file_path, basename($backup->file_path));
    }

    public function destroy(string $backupId, PlatformBackupService $backups): \Illuminate\Http\RedirectResponse
    {
        $backup = PlatformBackup::findOrFail($backupId);
        $backups->delete($backup);

        return back()->with('status', 'Backup removed.');
    }
}
