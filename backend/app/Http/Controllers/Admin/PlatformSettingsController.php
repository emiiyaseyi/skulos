<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;

/**
 * A single settings hub (user request 2026-07-18: "so many features that
 * we have in the school end are missing under the platform owner end").
 * Every platform-level settings screen already existed (Mail, Landing
 * Pages, Idle Timeout, Retention, Support Contact, Subscription Bank),
 * each independently routed and only reachable if you already knew its
 * URL or found it in the sidebar; this is just an index page linking all
 * of them in one place, no new settings or mutation logic of its own.
 */
class PlatformSettingsController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        return view('admin.settings-index');
    }
}
