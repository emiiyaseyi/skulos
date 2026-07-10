<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\School;
use App\Services\Tenancy\BackupService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\Rule;

/**
 * Platform admin side of backup/restore (user request 2026-07-09: "the
 * platform owner admin can initiate same too"). Same BackupService and
 * same backup records as the school's own backup page. "updateSchedule"
 * was missing here until 2026-07-10 (only existed on the school's own
 * portal, School\BackupController) even though the platform admin's
 * school detail page linked to a Backups card, so there was no way to
 * see or set a school's auto backup schedule from the admin side.
 */
class BackupAdminController extends Controller
{
    public function store(School $school, BackupService $backups): \Illuminate\Http\RedirectResponse
    {
        $backups->create($school, Backup::TYPE_MANUAL, Auth::user());

        return back()->with('status', "Backup created for {$school->name}.");
    }

    public function updateSchedule(Request $request, School $school): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'backup_frequency' => ['required', Rule::in(['none', 'daily', 'weekly', 'monthly'])],
            'backup_time' => ['nullable', 'date_format:H:i'],
        ]);

        $school->update([
            'backup_frequency' => $data['backup_frequency'],
            'backup_time' => $data['backup_frequency'] === 'none' ? null : ($data['backup_time'] ?? '02:00'),
        ]);

        return back()->with('status', "Backup schedule updated for {$school->name}.");
    }

    public function download(School $school, string $backupId): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $backup = Backup::where('school_id', $school->id)->findOrFail($backupId);
        abort_unless($backup->exists(), 404);

        return Storage::disk($backup->disk)->download($backup->file_path, basename($backup->file_path));
    }

    public function restore(School $school, string $backupId, BackupService $backups): \Illuminate\Http\RedirectResponse
    {
        $backup = Backup::where('school_id', $school->id)->findOrFail($backupId);
        $backups->restore($backup);

        return back()->with('status', "{$school->name} restored from the backup taken on ".$backup->created_at->toDayDateTimeString().'.');
    }
}
