<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

/**
 * Platform-wide default landing page per role (user request 2026-07-13:
 * "all the changes you added should also reflect for the platform owner
 * too who also have a central override for all the schools"). Mirrors
 * the platform/school SMTP settings split (App\Http\Controllers\Admin\
 * MailSettingsController): this is the platform-wide default, a school
 * can still set its own via School\LandingPageController, which wins
 * over this while a request is scoped to that school (see
 * LandingPageController::landingRouteFor()'s precedence).
 */
class LandingPageSettingsController extends Controller
{
    public const SETTING_KEY = 'landing_pages';

    public function edit(): \Illuminate\View\View
    {
        return view('admin.settings-landing-pages', [
            'roles' => config('skulos.roles'),
            'options' => config('skulos.landing_page_options'),
            'current' => PlatformSetting::grab(self::SETTING_KEY, []),
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'pages' => ['array'],
            'pages.*' => ['nullable', Rule::in(array_keys(config('skulos.landing_page_options')))],
        ]);

        $map = array_filter($data['pages'] ?? [], fn ($route) => ! empty($route));
        PlatformSetting::put(self::SETTING_KEY, $map);

        return back()->with('status', 'Platform default landing pages saved. A school can still override these for itself.');
    }
}
