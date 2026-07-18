<?php

namespace App\Services\ReportCards;

use App\Models\Tenant\Setting;

/**
 * A school's drag-and-drop header/identity-strip layout for the report
 * card (user request 2026-07-18: "let's make the report card a widget
 * approach and drag and drop approach"). Two zones only, Header and Info
 * Strip, each an ordered list of block keys from
 * config('skulos.report_card_layout_blocks'); a block left out of both is
 * simply not shown. Off by default (enabled() false) so every existing
 * template's fixed header/id-strip markup renders exactly as before until
 * a school deliberately turns this on, the same admin-editable-on-top-of-
 * a-sane-default shape ReportCardPreferences already established.
 */
class ReportCardLayout
{
    public const KEY_ENABLED = 'report_card_custom_layout_enabled';

    public const KEY_LAYOUT = 'report_card_layout';

    public const ZONES = ['header', 'info'];

    public function enabled(): bool
    {
        return (bool) Setting::grab(self::KEY_ENABLED, false);
    }

    /**
     * @return array<string, array<int, string>> zone => ordered block keys
     */
    public function zones(): array
    {
        $saved = Setting::grab(self::KEY_LAYOUT);

        return is_array($saved) ? $this->clean($saved) : config('skulos.default_report_card_layout', ['header' => [], 'info' => []]);
    }

    public function setEnabled(bool $enabled): void
    {
        Setting::put(self::KEY_ENABLED, $enabled);
    }

    /**
     * @param array<string, array<int, string>> $zones
     */
    public function setZones(array $zones): void
    {
        Setting::put(self::KEY_LAYOUT, $this->clean($zones));
    }

    protected function clean(array $zones): array
    {
        $valid = array_keys(config('skulos.report_card_layout_blocks', []));
        $clean = [];
        foreach (self::ZONES as $zone) {
            $clean[$zone] = array_values(array_intersect($zones[$zone] ?? [], $valid));
        }

        return $clean;
    }
}
