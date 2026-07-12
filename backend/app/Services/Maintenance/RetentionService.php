<?php

namespace App\Services\Maintenance;

use App\Models\PlatformSetting;
use App\Models\Tenant\Setting as TenantSetting;
use App\Services\Tenancy\TenantManager;

/**
 * How long notifications, communication logs, and platform sessions are
 * kept before an automatic cleanup removes them (Dbase recommendation:
 * "retention/cleanup policies for anything that grows unbounded"). Same
 * platform-then-school precedence as IdleTimeoutService/MailSettingsService
 * — a school's own setting wins if present, else the platform default,
 * else config('skulos.retention')'s fallback.
 */
class RetentionService
{
    public const KEY = 'retention';

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

    public function effective(): array
    {
        $default = [
            'notifications_days' => (int) config('skulos.retention.notifications_days', 90),
            'communication_logs_days' => (int) config('skulos.retention.communication_logs_days', 180),
            'sessions_days' => (int) config('skulos.retention.sessions_days', 30),
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
