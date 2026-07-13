<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\PlatformSetting;
use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\Setting;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Admin-configurable default landing page per role (user request
 * 2026-07-13: "Admin-configurable landing page per staff/student/parent
 * (default Dashboard)"). config('skulos.landing_page_options') is only
 * the seed catalog of available choices, the school's actual per-role
 * picks are DB-backed via tenant Setting "landing_pages" (a role slug to
 * route name map), matching the same seed-config-then-DB-Setting shape
 * report_card_review_chain and guardian_visibility already use.
 */
class LandingPageController extends Controller
{
    public const SETTING_KEY = 'landing_pages';

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

    /**
     * The route to send a user of this role to after login. Precedence:
     * this school's own choice (tenant Setting) > the platform owner's
     * central default (PlatformSetting, user request 2026-07-13: "all
     * the changes you added should also reflect for the platform owner
     * too who also have a central override for all the schools", the
     * same platform-default/school-override split MailSettingsService
     * already uses) > the Dashboard. Falls back to the Dashboard if
     * nothing is configured at either level, the configured route no
     * longer exists in the catalog, or the role no longer holds the
     * permission that route needs (a stale choice degrades quietly
     * rather than 403ing someone the moment they sign in).
     */
    public static function landingRouteFor(?Role $role): string
    {
        $fallback = 'school.dashboard';
        if (! $role) {
            return $fallback;
        }

        $schoolMap = Setting::grab(self::SETTING_KEY, []);
        $platformMap = PlatformSetting::grab(self::SETTING_KEY, []);
        $routeName = $schoolMap[$role->slug] ?? $platformMap[$role->slug] ?? null;
        $catalog = config('skulos.landing_page_options');

        if (! $routeName || ! isset($catalog[$routeName])) {
            return $fallback;
        }

        $permission = $catalog[$routeName]['permission'];
        if ($permission && ! $role->permissions()->where('code', $permission)->exists()) {
            return $fallback;
        }

        return $routeName;
    }

    public function edit(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.view');
        $school = $this->school($request);

        // Merge the platform's central default underneath this school's own
        // choices, so the dropdown shows what actually applies right now
        // (inherited from the platform owner) rather than always defaulting
        // to plain Dashboard until a school explicitly overrides it.
        $effective = array_merge(PlatformSetting::grab(self::SETTING_KEY, []), Setting::grab(self::SETTING_KEY, []));

        return view('school.settings-landing-pages', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'roles' => config('skulos.roles'),
            'options' => config('skulos.landing_page_options'),
            'current' => $effective,
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');

        $data = $request->validate([
            'pages' => ['array'],
            'pages.*' => ['nullable', Rule::in(array_keys(config('skulos.landing_page_options')))],
        ]);

        $map = array_filter($data['pages'] ?? [], fn ($route) => ! empty($route));
        Setting::put(self::SETTING_KEY, $map);

        return back()->with('status', 'Landing pages updated.');
    }
}
