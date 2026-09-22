<?php

namespace App\Services\ReportCards;

use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
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

    public const KEY_FOOTER_TEXT = 'report_card_footer_text';

    public const KEY_FOOTER_ALIGN = 'report_card_footer_align';

    public const KEY_PHYSICAL_STATS_TRACK_START = 'physical_stats_track_start';

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

    /**
     * The class-aware version of fields() (user directive 2026-09-22,
     * confirmed via AskUserQuestion: "Make these per-section too" --
     * Physical & Attendance and Stamp/Signature turned on for Nursery
     * must not also turn on for Primary/Secondary). A section's own
     * {field: bool} overrides win over the school wide fields() above,
     * a class's own win over its section's -- the same "own override
     * replaces the fallback for that one field, everything else
     * inherits" shape report_card_template/watermark_enabled/
     * tracks_physical_stats_start already use on these same two
     * tables, just generalised to any field code instead of one
     * dedicated column per field.
     *
     * @return array<string, bool>
     */
    public function fieldsFor(?SchoolClass $class): array
    {
        $resolved = $this->fields();

        foreach ([$class?->section?->branding?->field_overrides, $class?->field_overrides] as $override) {
            if (! is_array($override)) {
                continue;
            }
            foreach ($override as $field => $value) {
                if (array_key_exists($field, $resolved)) {
                    $resolved[$field] = (bool) $value;
                }
            }
        }

        return $resolved;
    }

    public function showsFor(string $field, ?SchoolClass $class): bool
    {
        return (bool) ($this->fieldsFor($class)[$field] ?? false);
    }

    /**
     * The base template a report card actually renders with, for a
     * student in the given section: that section's own override if it
     * has one, otherwise the school wide choice (user directive
     * 2026-09-21: "I have a school who have different report card for
     * each section of their school"). A caller that has already decided
     * this student's class is in Narrative Evaluation mode for the term
     * being rendered overrides this entirely with 'narrative', same as
     * before this method existed -- narrative-or-not is decided one
     * level up, this only ever picks the SCORED look.
     */
    public function templateFor(?Section $section): string
    {
        $override = $section?->reportCardTemplate();
        $templates = array_keys(config('skulos.report_card_templates', []));

        return ($override && in_array($override, $templates, true)) ? $override : $this->template();
    }

    /**
     * The finer, class-level version of templateFor() (user directive
     * 2026-09-21: "one can tick the section or classes"): this class's
     * own override, then its section's, then the school wide default.
     */
    public function templateForClass(?SchoolClass $class): string
    {
        $override = $class?->reportCardTemplate() ?: $class?->section?->reportCardTemplate();
        $templates = array_keys(config('skulos.report_card_templates', []));

        return ($override && in_array($override, $templates, true)) ? $override : $this->template();
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

    /**
     * A school's own footer text (user request 2026-07-24: "a footer
     * for report card where the school type what they want as the
     * footer but by default picks the motto under school profile").
     * Null (never explicitly set) means "use the motto instead", the
     * caller's job since this service does not read the School model.
     */
    public function footerText(): ?string
    {
        $saved = Setting::grab(self::KEY_FOOTER_TEXT);

        return is_string($saved) && trim($saved) !== '' ? $saved : null;
    }

    public function setFooterText(?string $text): void
    {
        Setting::put(self::KEY_FOOTER_TEXT, $text !== null && trim($text) !== '' ? $text : null);
    }

    /** @return 'left'|'center'|'right' */
    public function footerAlign(): string
    {
        $saved = Setting::grab(self::KEY_FOOTER_ALIGN);

        return in_array($saved, ['left', 'center', 'right'], true) ? $saved : 'center';
    }

    public function setFooterAlign(string $align): void
    {
        Setting::put(self::KEY_FOOTER_ALIGN, in_array($align, ['left', 'center', 'right'], true) ? $align : 'center');
    }

    /**
     * Whether Physical & Attendance tracks a Beginning-of-Term value
     * alongside the existing End-of-Term one (user directive 2026-09-21,
     * confirmed via AskUserQuestion: "add it to what admin can enable if
     * they want the stat to have beginning of term and end of term or
     * just one to cover it"). Off by default -- every existing school's
     * report card, and Class Teacher Entry screen, is completely
     * unaffected until a school deliberately turns this on.
     */
    public function tracksPhysicalStatsStart(): bool
    {
        return (bool) Setting::grab(self::KEY_PHYSICAL_STATS_TRACK_START, false);
    }

    public function setTracksPhysicalStatsStart(bool $tracks): void
    {
        Setting::put(self::KEY_PHYSICAL_STATS_TRACK_START, $tracks);
    }

    /**
     * The class-aware version of tracksPhysicalStatsStart() (user
     * directive 2026-09-22, confirmed via AskUserQuestion: "Make it
     * per-section... a real schema change"): a class's own choice, else
     * its section's, else the school wide Setting above -- so a school
     * (PPMS) can turn this on for Nursery only, without Primary/
     * Secondary's report cards also switching over.
     */
    public function tracksPhysicalStatsStartFor(?SchoolClass $class): bool
    {
        $resolved = $class?->resolvedTracksPhysicalStatsStart();

        return $resolved ?? $this->tracksPhysicalStatsStart();
    }
}
