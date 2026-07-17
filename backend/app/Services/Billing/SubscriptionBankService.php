<?php

namespace App\Services\Billing;

use App\Models\PlatformSetting;

/**
 * The bank account a school transfers its SKULOS subscription payment
 * into (user directive 2026-07-17, real details given directly, not
 * fabricated: account 0036905082, GTBank, Michael Oluwaseyi Adeniran).
 * Same PlatformSetting-override-wins-else-config-default shape as
 * SupportContactService/MailSettingsService, so a platform admin can
 * change it later without a code deploy.
 */
class SubscriptionBankService
{
    public const KEY = 'subscription_bank';

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
            'bank_name' => config('skulos.subscription_bank.bank_name'),
            'account_name' => config('skulos.subscription_bank.account_name'),
            'account_number' => config('skulos.subscription_bank.account_number'),
        ];

        return array_merge($default, array_filter(
            $this->platformSettings(),
            fn ($v) => $v !== null && $v !== ''
        ));
    }
}
