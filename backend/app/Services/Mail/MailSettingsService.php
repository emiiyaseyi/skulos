<?php

namespace App\Services\Mail;

use App\Models\PlatformSetting;
use App\Models\Tenant\Setting as TenantSetting;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Crypt;

/**
 * Applies admin configured SMTP settings at runtime (user request
 * 2026-07-09: "allow me to setup SMTP server settings for the platform
 * and also for each school if they want"). .env / config/mail.php stay
 * the fallback default; whatever is saved here wins when present.
 *
 * Precedence for a request inside a school's tenant context: that
 * school's own SMTP settings, else the platform's, else .env.
 */
class MailSettingsService
{
    public const KEY = 'smtp';

    protected const FIELDS = ['host', 'port', 'username', 'password', 'encryption', 'from_address', 'from_name'];

    public function platformSettings(): array
    {
        return $this->decryptPassword((array) PlatformSetting::grab(self::KEY, []));
    }

    public function savePlatformSettings(array $data): void
    {
        $current = (array) PlatformSetting::grab(self::KEY, []);
        PlatformSetting::put(self::KEY, $this->clean($data, $current));
    }

    /** Only meaningful once the tenant connection points at a school. */
    public function schoolSettings(): array
    {
        return $this->decryptPassword((array) TenantSetting::grab(self::KEY, []));
    }

    public function saveSchoolSettings(array $data): void
    {
        $current = (array) TenantSetting::grab(self::KEY, []);
        TenantSetting::put(self::KEY, $this->clean($data, $current));
    }

    /**
     * Apply the platform's saved SMTP settings, if any (called once early
     * in the request lifecycle, before any school specific override).
     */
    public function applyPlatformDefaults(): void
    {
        $this->apply($this->platformSettings());
    }

    /**
     * Apply this school's own SMTP settings on top, if it has set any
     * (called by ResolveTenant right after the tenant connection is
     * established, so it always runs after applyPlatformDefaults()).
     */
    public function applySchoolOverride(): void
    {
        $this->apply($this->schoolSettings());
    }

    protected function apply(array $settings): void
    {
        if (empty(array_filter($settings))) {
            return;
        }

        if (! empty($settings['host'])) {
            Config::set('mail.mailers.smtp.host', $settings['host']);
            Config::set('mail.mailers.smtp.port', $settings['port'] ?? 587);
            Config::set('mail.mailers.smtp.username', $settings['username'] ?? null);
            Config::set('mail.mailers.smtp.password', $settings['password'] ?? null);
            Config::set('mail.mailers.smtp.scheme', $settings['encryption'] ?? null);
            Config::set('mail.default', 'smtp');
        }
        if (! empty($settings['from_address'])) {
            Config::set('mail.from.address', $settings['from_address']);
            Config::set('mail.from.name', $settings['from_name'] ?? config('app.name'));
        }
    }

    /**
     * Keeps the SMTP password encrypted at rest. An empty submitted
     * password means "leave the current one alone" (the settings form
     * never round-trips the real password back into the page).
     */
    protected function clean(array $data, array $current = []): array
    {
        $data = array_intersect_key($data, array_flip(self::FIELDS));

        if (empty($data['password'])) {
            $data['password'] = $current['password'] ?? null;
        } else {
            $data['password'] = Crypt::encryptString($data['password']);
        }

        return $data;
    }

    protected function decryptPassword(array $settings): array
    {
        if (! empty($settings['password'])) {
            try {
                $settings['password'] = Crypt::decryptString($settings['password']);
            } catch (\Throwable $e) {
                $settings['password'] = null;
            }
        }

        return $settings;
    }
}
