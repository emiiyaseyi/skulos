<?php

namespace App\Services\Accounts;

use App\Models\Tenant\Setting;
use Illuminate\Support\Carbon;

/**
 * The role-specific brief line shown on a new account's welcome email, and
 * the admin's "pause welcome emails for a while" switch (user request
 * 2026-07-26: "New user accounts get a welcome email with login details,
 * portal link, and a role-specific brief; admin can disable email
 * notifications for a period under email settings"). The pause is scoped
 * to this one welcome email, not the school's mail system generally,
 * there is already a separate, unrelated toggle for announcement email
 * (CommunicationService::channelEnabled()).
 */
class WelcomeEmailService
{
    public const KEY_BRIEFS = 'role_welcome_briefs';

    public const KEY_PAUSED_UNTIL = 'account_welcome_emails_paused_until';

    protected const FALLBACK_BRIEF = 'Sign in any time to see what your role can do on SKULOS.';

    /** Every role slug's brief, the school's own override if it saved one, otherwise the seed default. */
    public function allBriefs(): array
    {
        $saved = Setting::grab(self::KEY_BRIEFS, []);
        $saved = is_array($saved) ? $saved : [];
        $defaults = config('skulos.default_role_welcome_briefs', []);

        $briefs = [];
        foreach (array_keys($defaults) as $slug) {
            $briefs[$slug] = $saved[$slug] ?? $defaults[$slug];
        }

        return $briefs;
    }

    public function briefFor(string $roleSlug): string
    {
        return $this->allBriefs()[$roleSlug] ?? self::FALLBACK_BRIEF;
    }

    /**
     * @param  array<string, string>  $briefs  role slug => text
     */
    public function setBriefs(array $briefs): void
    {
        $defaults = config('skulos.default_role_welcome_briefs', []);
        $clean = [];
        foreach ($briefs as $slug => $text) {
            if (! array_key_exists($slug, $defaults)) {
                continue;
            }
            $text = trim((string) $text);
            if ($text !== '' && $text !== $defaults[$slug]) {
                $clean[$slug] = mb_substr($text, 0, 240);
            }
        }
        Setting::put(self::KEY_BRIEFS, $clean);
    }

    public function isPaused(): bool
    {
        $until = $this->pausedUntil();

        return $until !== null && $until->isFuture();
    }

    public function pausedUntil(): ?Carbon
    {
        $value = Setting::grab(self::KEY_PAUSED_UNTIL);

        return $value ? Carbon::parse($value) : null;
    }

    public function pauseUntil(?string $isoDate): void
    {
        Setting::put(self::KEY_PAUSED_UNTIL, $isoDate ?: null);
    }
}
