<?php

namespace App\Services\ReportCards;

use App\Models\Tenant\Setting;

/**
 * Reads and writes a school's report card look: which template it uses
 * and which fields are shown. Stored in tenant settings so it is fully
 * admin editable; falls back to the config catalog defaults. Mirrors
 * App\Services\Dashboard\DashboardPreferences.
 */
class ReportCardPreferences
{
    public const KEY_TEMPLATE = 'report_card_template';

    public const KEY_FIELDS = 'report_card_fields';

    public function template(): string
    {
        $chosen = Setting::grab(self::KEY_TEMPLATE);
        $templates = array_keys(config('skulos.report_card_templates', []));

        return in_array($chosen, $templates, true)
            ? $chosen
            : config('skulos.default_report_card_template', 'classic');
    }

    /**
     * field_code => visible? merged over the catalog defaults.
     *
     * @return array<string, bool>
     */
    public function fields(): array
    {
        $defaults = collect(config('skulos.report_card_fields', []))
            ->map(fn ($f) => (bool) ($f['default'] ?? true))
            ->all();

        $saved = Setting::grab(self::KEY_FIELDS);

        return is_array($saved) ? array_merge($defaults, array_map('boolval', $saved)) : $defaults;
    }

    public function shows(string $field): bool
    {
        return (bool) ($this->fields()[$field] ?? false);
    }

    public function setTemplate(string $template): void
    {
        if (array_key_exists($template, config('skulos.report_card_templates', []))) {
            Setting::put(self::KEY_TEMPLATE, $template);
        }
    }

    /**
     * @param array<string, bool> $fields
     */
    public function setFields(array $fields): void
    {
        $valid = array_keys(config('skulos.report_card_fields', []));
        $clean = [];
        foreach ($valid as $code) {
            $clean[$code] = (bool) ($fields[$code] ?? false);
        }
        Setting::put(self::KEY_FIELDS, $clean);
    }
}
