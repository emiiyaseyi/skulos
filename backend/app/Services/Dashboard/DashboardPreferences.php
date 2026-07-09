<?php

namespace App\Services\Dashboard;

use App\Models\Tenant\Setting;

/**
 * Reads and writes a school's dashboard look: which layout template it uses
 * and which widgets are shown. Stored in tenant settings so it is fully
 * admin editable; falls back to the config catalog defaults.
 */
class DashboardPreferences
{
    public const KEY_LAYOUT = 'dashboard_layout';

    public const KEY_WIDGETS = 'dashboard_widgets';

    public function layout(): string
    {
        $chosen = Setting::grab(self::KEY_LAYOUT);
        $layouts = array_keys(config('skulos.dashboard_layouts', []));

        return in_array($chosen, $layouts, true)
            ? $chosen
            : config('skulos.default_dashboard_layout', 'classic');
    }

    /**
     * widget_code => visible? merged over the catalog defaults.
     *
     * @return array<string, bool>
     */
    public function widgets(): array
    {
        $defaults = collect(config('skulos.dashboard_widgets', []))
            ->map(fn ($w) => (bool) ($w['default'] ?? true))
            ->all();

        $saved = Setting::grab(self::KEY_WIDGETS);

        return is_array($saved) ? array_merge($defaults, array_map('boolval', $saved)) : $defaults;
    }

    public function shows(string $widget): bool
    {
        return (bool) ($this->widgets()[$widget] ?? false);
    }

    public function setLayout(string $layout): void
    {
        if (array_key_exists($layout, config('skulos.dashboard_layouts', []))) {
            Setting::put(self::KEY_LAYOUT, $layout);
        }
    }

    /**
     * @param array<string, bool> $widgets
     */
    public function setWidgets(array $widgets): void
    {
        $valid = array_keys(config('skulos.dashboard_widgets', []));
        $clean = [];
        foreach ($valid as $code) {
            $clean[$code] = (bool) ($widgets[$code] ?? false);
        }
        Setting::put(self::KEY_WIDGETS, $clean);
    }
}
