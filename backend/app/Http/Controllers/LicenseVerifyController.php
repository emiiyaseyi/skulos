<?php

namespace App\Http\Controllers;

use App\Services\Licensing\LicenseService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

/**
 * Public check-in endpoint a self-hosted deployment calls on a schedule
 * (App\Console\Commands\CheckSelfHostedLicense). See LicenseService for
 * the full design note on why this exists.
 */
class LicenseVerifyController extends Controller
{
    public function verify(Request $request, LicenseService $licenses): JsonResponse
    {
        $data = $request->validate(['license_key' => ['required', 'string', 'max:60']]);

        return response()->json($licenses->verifyIncoming($data['license_key']));
    }
}
