<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Services\Security\IdleTimeoutService;
use Illuminate\Http\Request;

/**
 * Platform wide idle sign out timeout (user request 2026-07-11). A school
 * can also set its own (School\IdleTimeoutController), which wins over
 * this default while a request is scoped to that school.
 */
class IdleTimeoutController extends Controller
{
    public function edit(IdleTimeoutService $idle): \Illuminate\View\View
    {
        return view('admin.idle-timeout-settings', ['settings' => $idle->platformSettings()]);
    }

    public function update(Request $request, IdleTimeoutService $idle): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'enabled' => ['sometimes', 'boolean'],
            'idle_seconds' => ['required', 'integer', 'min:10', 'max:86400'],
            'warning_seconds' => ['required', 'integer', 'min:5', 'max:600'],
        ]);

        $idle->savePlatformSettings([
            'enabled' => $request->boolean('enabled'),
            'idle_seconds' => $data['idle_seconds'],
            'warning_seconds' => $data['warning_seconds'],
        ]);

        return back()->with('status', 'Platform idle timeout settings saved.');
    }
}
