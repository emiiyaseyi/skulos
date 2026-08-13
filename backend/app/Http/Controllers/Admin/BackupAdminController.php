<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\Backup;
use App\Models\School;
use App\Services\Maintenance\BackupRetentionService;
use App\Services\Maintenance\MaintenanceActionsService;
use App\Services\PlatformBackupService;
use App\Services\Tenancy\BackupService;
use App\Services\Tenancy\TenantManager;
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
    /**
     * A consolidated backups matrix (user request 2026-07-18: "so many
     * features that we have in the school end are missing under the
     * platform owner end"), the same cross-school-matrix pattern
     * ModuleAdminController already established: previously the platform
     * admin could only see/initiate a backup one school at a time, from
     * inside that school's own detail page.
     */
    public function index(): \Illuminate\View\View
    {
        $schools = School::whereNotNull('provisioned_at')->orderBy('name')->get();

        $latestBackups = Backup::whereIn('school_id', $schools->pluck('id'))
            ->orderByDesc('created_at')
            ->get()
            ->unique('school_id')
            ->keyBy('school_id');

        return view('admin.backups', [
            'schools' => $schools,
            'latestBackups' => $latestBackups,
        ]);
    }

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

        try {
            $backups->restore($backup);
        } catch (\RuntimeException $e) {
            return back()->with('error', "Restore for {$school->name} failed, its current data was not touched: ".$e->getMessage());
        }

        return back()->with('status', "{$school->name} restored from the backup taken on ".$backup->created_at->toDayDateTimeString().'.');
    }

    /**
     * On-demand version of the weekly skulos:retention-cleanup job's own
     * backup pruning (2026-08-12) — every provisioned school plus the
     * platform's own backups, in one click, for an admin who wants to
     * check disk usage right now rather than wait for the next scheduled
     * run. Wraps each school in TenantManager::use()/forget() so a
     * school's own backup-retention override (set from its own Settings)
     * is actually respected here, not just the platform default.
     */
    public function pruneAll(MaintenanceActionsService $actions, BackupRetentionService $backupRetention, PlatformBackupService $platformBackups, TenantManager $tenants): \Illuminate\Http\RedirectResponse
    {
        $deleted = 0;
        $freedBytes = 0;

        foreach (School::whereNotNull('database')->whereNotNull('provisioned_at')->get() as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable) {
                continue;
            }
            $result = $actions->pruneBackups($school, $backupRetention);
            $tenants->forget();
            $deleted += $result['deleted'];
            $freedBytes += $result['freed_bytes'];
        }

        $platformResult = $platformBackups->prune($backupRetention);
        $deleted += $platformResult['deleted'];
        $freedBytes += $platformResult['freed_bytes'];

        return back()->with('status', $deleted
            ? "Pruned {$deleted} old backup(s) across every school and the platform, freeing ".\App\Services\Maintenance\StorageService::humanBytes($freedBytes).'.'
            : 'No backups were old enough to prune.');
    }
}
