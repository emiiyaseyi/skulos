<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Licensing\LicenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Self-hosted side of licensing (user request 2026-07-18): whoever sets
 * up a self-hosted install enters the license key the main SKULOS
 * platform issued them here, and can see this installation's own
 * check-in status. Only meaningful when config('skulos.self_hosted') is
 * true; the edit screen still works either way, but explains itself
 * plainly when it is not, since App\Console\Commands\
 * CheckSelfHostedLicense no-ops until the env flag is set.
 */
class LicenseSettingsController extends Controller
{
    public function edit(): View
    {
        $school = School::orderBy('id')->first();

        return view('admin.settings-self-hosted-license', [
            'school' => $school,
            'graceDaysRemaining' => $school ? app(LicenseService::class)->graceDaysRemaining($school) : null,
        ]);
    }

    public function update(Request $request): RedirectResponse
    {
        $school = School::orderBy('id')->firstOrFail();

        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:60'],
        ]);

        $school->update([
            'is_self_hosted' => true,
            'license_key' => $data['license_key'],
        ]);

        return back()->with('status', 'License key saved. It will be verified on the next scheduled check.');
    }
}
