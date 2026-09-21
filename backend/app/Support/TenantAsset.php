<?php

namespace App\Support;

use App\Services\Tenancy\TenantManager;
use Illuminate\Support\Facades\Storage;

/**
 * A public-disk file's URL, built against the CURRENT SCHOOL's own real
 * subdomain (School::portalUrl(), e.g. https://ppms.skulosapp.com/storage/...)
 * rather than Storage::disk('public')->url(), which is built from the
 * single, platform-wide, non-tenant-aware APP_URL config value.
 *
 * Real bug found live (user report 2026-09-21, a broken image on a
 * report card preview): APP_URL on this multi-tenant platform is one
 * fixed value (the bare root domain, not any one school's own
 * subdomain), so every Storage::disk('public')->url() call across the
 * whole app has always generated a URL pointing at the platform's own
 * root domain rather than the school actually being viewed -- and on
 * this host, the bare root domain does not serve the same document
 * root as the `*.skulosapp.com` wildcard every real school lives under,
 * so the image 404s. School::portalUrl() already exists and already
 * builds the correct per-school subdomain URL for exactly this reason
 * (used for emailed links); this is that same building block applied
 * to file URLs.
 *
 * Only ever used for a report card in this pass (the actively reported
 * break) -- the same broken pattern exists in ~20 other files across
 * the app (Admissions, Chat, CBT, student avatars, branding settings,
 * ...) and is a real, separate, larger follow-up, not silently swept
 * up here.
 */
class TenantAsset
{
    public static function url(?string $path): ?string
    {
        if (! $path) {
            return null;
        }

        $school = app(TenantManager::class)->current();

        return $school ? $school->portalUrl('storage/'.ltrim($path, '/')) : Storage::disk('public')->url($path);
    }
}
