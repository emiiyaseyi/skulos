<?php

use Illuminate\Support\Facades\Route;

/*
|--------------------------------------------------------------------------
| Self-hosted licensing API
|--------------------------------------------------------------------------
| Called by a school's own self-hosted deployment, never by a browser — no
| session, no CSRF token. Deliberately its own routes/api.php (registered
| via bootstrap/app.php's withRouting(api: ...), Laravel's stateless "api"
| middleware group with no CSRF, plus the automatic "/api" URI prefix)
| rather than routes/web.php, which every route here originally lived in.
|
| Real bug found and fixed 2026-08-07: routes/web.php applies Laravel's
| default "web" middleware group, CSRF included — a genuine external
| self-hosted server's check-in has no CSRF token and would have gotten a
| raw 419 in production. The existing SelfHostedLicenseTest only ever
| passed because PHPUnit's own test client auto-bypasses CSRF in testing
| mode; that bypass does not exist for a real HTTP request. The license
| key itself is the only real credential these routes ever check.
|
| The automatic "/api" prefix means a route defined here as "/license/
| verify" resolves to the exact same "/api/license/verify" URL this
| already-shipped endpoint always had, so no already-configured
| self-hosted install's SKULOS_LICENSE_ENDPOINT breaks.
*/

Route::post('/license/verify', [\App\Http\Controllers\LicenseVerifyController::class, 'verify'])
    ->middleware('throttle:30,1')
    ->name('license.verify');

Route::post('/license/latest-release', [\App\Http\Controllers\ReleaseDownloadController::class, 'latestRelease'])
    ->middleware('throttle:30,1')
    ->name('license.latest-release');

Route::post('/license/download', [\App\Http\Controllers\ReleaseDownloadController::class, 'download'])
    ->middleware('throttle:30,1')
    ->name('license.download');

/*
|--------------------------------------------------------------------------
| Marketing site contact form
|--------------------------------------------------------------------------
| Same reasoning as the license routes above: contact.html is a plain
| static page with no Blade templating to embed a CSRF token into, so
| this lives here (stateless, no CSRF) rather than in routes/web.php.
*/
Route::post('/contact', [\App\Http\Controllers\ContactFormController::class, 'store'])
    ->middleware('throttle:10,1')
    ->name('contact.store');

/*
|--------------------------------------------------------------------------
| Marketing site visitor tracking beacon
|--------------------------------------------------------------------------
| Same reasoning as the contact form above (stateless, no CSRF, called
| from a plain <script> on static HTML). Generous per-IP throttle since
| this fires on every page a real visitor loads, not once per form.
*/
Route::post('/marketing/track', [\App\Http\Controllers\MarketingTrackController::class, 'store'])
    ->middleware('throttle:120,1')
    ->name('marketing.track');
