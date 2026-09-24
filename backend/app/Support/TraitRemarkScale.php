<?php

namespace App\Support;

use App\Models\Tenant\Setting;

/**
 * The words a class teacher picks from when rating a remark type
 * behavioural trait (Psychomotor / Affective domains and the plain traits
 * list). A school's own list (tenant Setting, edited on the Behavioural
 * Traits screen) wins; a school that never set one keeps the platform
 * default in config('skulos.behavioural_trait_remark_scale'), so nothing
 * changes for it.
 */
class TraitRemarkScale
{
    public const SETTING = 'behavioural_trait_remark_scale';

    /** @return list<string> */
    public static function options(): array
    {
        $saved = Setting::grab(self::SETTING);
        if (is_string($saved)) {
            $saved = json_decode($saved, true);
        }
        $clean = is_array($saved)
            ? array_values(array_filter(array_map(fn ($v) => is_string($v) ? trim($v) : '', $saved), fn ($v) => $v !== ''))
            : [];

        return $clean ?: array_values(config('skulos.behavioural_trait_remark_scale', []));
    }

    /** @param list<string> $options */
    public static function save(array $options): void
    {
        Setting::put(self::SETTING, array_values($options));
    }
}
