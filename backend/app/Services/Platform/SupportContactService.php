<?php

namespace App\Services\Platform;

use App\Models\PlatformSetting;

/**
 * The support email/phone shown on the login page and in every
 * transactional email footer used to be read straight from
 * config('skulos.support') (itself just env()), so a platform admin could
 * never change it without a code deploy. Same shape as IdleTimeoutService:
 * a PlatformSetting override wins if set, else config's env-backed
 * fallback, so nothing breaks before the admin ever visits the settings
 * screen.
 */
class SupportContactService
{
    public const KEY = 'support_contact';

    public function platformSettings(): array
    {
        return (array) PlatformSetting::grab(self::KEY, []);
    }

    public function saveSettings(array $data): void
    {
        PlatformSetting::put(self::KEY, $data);
    }

    public function effective(): array
    {
        $default = [
            'email' => config('skulos.support.email'),
            'phone' => config('skulos.support.phone'),
        ];

        return array_merge($default, array_filter(
            $this->platformSettings(),
            fn ($v) => $v !== null && $v !== ''
        ));
    }
}
