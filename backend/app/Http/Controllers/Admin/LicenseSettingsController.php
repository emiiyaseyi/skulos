<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Licensing\LicenseService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
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

    /**
     * Release management, self-hosted side (2026-08-07): downloads the
     * latest published release from the main platform, verifies its
     * sha256 against what the platform itself reports (mirrors
     * BackupService::restore()'s own "verify before trusting" discipline
     * — a corrupted/tampered transfer is discarded, never left half
     * saved), and stops there. Applying it (extract, composer install,
     * migrate --force) is a deliberate manual step over SSH, documented
     * in DEPLOYMENT.md — this never extracts or runs anything itself.
     */
    public function downloadUpdate(): RedirectResponse
    {
        $school = School::orderBy('id')->firstOrFail();
        abort_unless($school->license_key, 404);

        $meta = Http::timeout(10)->post(
            config('skulos.self_hosted_license.latest_release_endpoint'),
            ['license_key' => $school->license_key]
        );

        if (! $meta->successful() || ! $meta->json('valid') || ! $meta->json('latest_version')) {
            return back()->with('error', 'Could not reach the platform to check for a release right now. Try again shortly.');
        }

        $version = $meta->json('latest_version');
        $expectedChecksum = $meta->json('latest_release_checksum');

        $file = Http::timeout(120)->post(
            config('skulos.self_hosted_license.download_endpoint'),
            ['license_key' => $school->license_key, 'version' => $version]
        );

        if (! $file->successful()) {
            return back()->with('error', 'The release download failed. Try again shortly.');
        }

        $actualChecksum = hash('sha256', $file->body());
        if (! $expectedChecksum || ! hash_equals($expectedChecksum, $actualChecksum)) {
            return back()->with('error', "Downloaded release {$version} failed checksum verification and was discarded. Nothing was applied — try downloading again.");
        }

        $relativePath = "updates/{$version}.zip";
        Storage::disk('local')->put($relativePath, $file->body());

        return back()->with('status',
            "Release {$version} downloaded and verified, saved to storage/app/{$relativePath}. ".
            'Apply it yourself over SSH: extract it, run composer install --no-dev, then php artisan migrate --force, '.
            "then set SKULOS_VERSION={$version} in .env — see \"Updating an existing self-hosted install\" in DEPLOYMENT.md.");
    }
}
