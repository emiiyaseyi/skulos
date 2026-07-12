<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Maintenance\RetentionService;
use Illuminate\Http\Request;

/**
 * Platform wide retention windows (Dbase recommendation: "retention/
 * cleanup policies for anything that grows unbounded"). A school can also
 * set its own (School\RetentionController), which wins over this default.
 */
class RetentionController extends Controller
{
    public function edit(RetentionService $retention): \Illuminate\View\View
    {
        return view('admin.retention-settings', ['settings' => $retention->platformSettings()]);
    }

    public function update(Request $request, RetentionService $retention): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'notifications_days' => ['required', 'integer', 'min:7', 'max:3650'],
            'communication_logs_days' => ['required', 'integer', 'min:7', 'max:3650'],
            'sessions_days' => ['required', 'integer', 'min:1', 'max:365'],
        ]);

        $retention->savePlatformSettings($data);

        return back()->with('status', 'Platform retention settings saved.');
    }
}
