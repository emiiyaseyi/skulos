<?php

namespace App\Http\Controllers;

use App\Models\Release;
use App\Services\Licensing\LicenseService;
use App\Services\Licensing\ReleaseService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;

/**
 * Self-hosted licensing: release management (2026-08-07). Called by a
 * school's own self-hosted deployment, never by a browser — same
 * no-session, license-key-is-the-credential shape as LicenseVerifyController,
 * registered alongside it in routes/api.php. A license that can't verify
 * can't download either: the same incentive that already gates runtime
 * access now also gates updates, no separate rule to maintain.
 */
class ReleaseDownloadController extends Controller
{
    /** A lighter-weight companion to /license/verify, for a self-hosted install that wants to check for an update without waiting for its next hourly check-in. */
    public function latestRelease(Request $request, LicenseService $licenses): \Illuminate\Http\JsonResponse
    {
        $data = $request->validate(['license_key' => ['required', 'string', 'max:60']]);
        $result = $licenses->verifyIncoming($data['license_key']);

        if (! $result['valid']) {
            return response()->json($result);
        }

        return response()->json([
            'valid' => true,
            'latest_version' => $result['latest_version'] ?? null,
            'latest_release_notes' => $result['latest_release_notes'] ?? null,
            'latest_release_checksum' => $result['latest_release_checksum'] ?? null,
        ]);
    }

    public function download(Request $request, LicenseService $licenses, ReleaseService $releases): \Symfony\Component\HttpFoundation\StreamedResponse|\Illuminate\Http\JsonResponse
    {
        $data = $request->validate([
            'license_key' => ['required', 'string', 'max:60'],
            'version' => ['nullable', 'string', 'max:40'],
        ]);

        $result = $licenses->verifyIncoming($data['license_key']);
        if (! $result['valid']) {
            return response()->json($result, 403);
        }

        $release = isset($data['version'])
            ? Release::published()->where('version', $data['version'])->first()
            : $releases->latestPublished();

        if (! $release || ! $release->exists()) {
            return response()->json(['valid' => true, 'error' => 'release_not_found'], 404);
        }

        return Storage::disk('local')->download($release->file_path, basename($release->file_path));
    }
}
