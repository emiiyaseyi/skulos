<?php

namespace App\Services\Security;

use App\Models\PlatformSetting;
use App\Models\Tenant\Setting as TenantSetting;
use App\Services\Tenancy\TenantManager;

/**
 * The idle sign out timeout was hardcoded (user request 2026-07-11:
 * "let's add the feature under super admin and admin to control the idle
 * time out of the platform which is currently hardcoded to 30 seconds,
 * the admins can change the seconds or turn it off"). Same precedence as
 * SMTP settings (MailSettingsService): a school's own setting wins if
 * present, else the platform default, else config/skulos.php's fallback.
 */
class IdleTimeoutService
{
    public const KEY = 'idle_timeout';

    public function platformSettings(): array
    {
        return (array) PlatformSetting::grab(self::KEY, []);
    }

    public function savePlatformSettings(array $data): void
    {
        PlatformSetting::put(self::KEY, $data);
    }

    /** Only meaningful once the tenant connection points at a school. */
    public function schoolSettings(): array
    {
        return (array) TenantSetting::grab(self::KEY, []);
    }

    public function saveSchoolSettings(array $data): void
    {
        TenantSetting::put(self::KEY, $data);
    }

    /**
     * The values actually in effect for the current request: school
     * override if one is set and a school context is active, else the
     * platform default if set, else config/skulos.php's fallback.
     */
    public function effective(): array
    {
        $default = [
            'enabled' => true,
            'idle_seconds' => (int) config('skulos.session_timeout.idle_seconds', 30),
            'warning_seconds' => (int) config('skulos.session_timeout.warning_seconds', 15),
        ];

        $platform = $this->platformSettings();
        $resolved = array_merge($default, array_filter($platform, fn ($v) => $v !== null && $v !== ''));

        if (app(TenantManager::class)->current()) {
            $school = array_filter($this->schoolSettings(), fn ($v) => $v !== null && $v !== '');
            $resolved = array_merge($resolved, $school);
        }

        return $resolved;
    }
}
