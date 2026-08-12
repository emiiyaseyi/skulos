<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Maintenance\HealthService;
use App\Services\Maintenance\MaintenanceActionsService;
use App\Services\Maintenance\RetentionService;
use App\Services\Maintenance\StorageService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * School Health & Storage (Dbase recommendation, three related items built
 * together per the tracker's own note: a per-school Storage Dashboard, a
 * Maintenance Center, and the "SKULOS Health Engine"). One screen rather
 * than three separate ones — they share the same data and the same
 * audience, and splitting them apart would just be three near empty pages
 * today given how much of the aspirational checklist (compress images,
 * remove duplicate files, rebuild search index, orphaned uploads) has no
 * underlying feature to act on yet, see the "Not built" note on the
 * matching Build Log entry.
 */
class MaintenanceController extends Controller
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

    public function index(Request $request, StorageService $storage, HealthService $health): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.view');
        $school = $this->school($request);

        return view('school.maintenance.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'storage' => $storage->breakdown($school),
            'health' => $health->check($school),
            'canManage' => app(Rbac::class)->check(Auth::user(), $school, 'school_info.manage_settings'),
            'latestBackup' => \App\Models\Backup::where('school_id', $school->id)->orderByDesc('id')->first(),
        ]);
    }

    public function optimizeNow(Request $request, MaintenanceActionsService $actions): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $school = $this->school($request);

        $result = $actions->optimize($school);

        return back()->with('status', $result['driver'] === 'mysql'
            ? "Database optimized: {$result['tables_touched']} table(s)."
            : 'Database optimized.');
    }

    public function runRetentionNow(Request $request, MaintenanceActionsService $actions, RetentionService $retention): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $school = $this->school($request);

        $result = $actions->purgeRetention($school, $retention);

        return back()->with('status', "Cleanup complete: purged {$result['notifications']} read notification(s), {$result['communication_logs']} communication log(s) past their retention window.");
    }
}
