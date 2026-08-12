<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Maintenance\StorageService;

/**
 * Platform admin cross-school storage matrix (feedback list 8/7/2026,
 * items 12-13: the school-side Health & Storage screen got a real
 * per-category byte breakdown, and "do same for all applicable roles
 * like the platform owner too"). Same cross-school-matrix shape
 * BackupAdminController::index() already established. Real measured
 * bytes per school — database, uploaded files, backups — never an
 * estimate, sorted largest first so the heaviest school is always at
 * the top.
 */
class StorageAdminController extends Controller
{
    public function index(StorageService $storage): \Illuminate\View\View
    {
        $rows = School::whereNotNull('provisioned_at')->orderBy('name')->get()
            ->map(fn (School $school) => array_merge(
                ['school' => $school],
                $storage->summaryFor($school)
            ))
            ->sortByDesc('total_bytes')
            ->values();

        return view('admin.storage', [
            'rows' => $rows,
            'grandTotal' => $rows->sum('total_bytes'),
        ]);
    }
}
