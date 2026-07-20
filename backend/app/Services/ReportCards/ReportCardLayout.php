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

    public const KEY_BODY_ORDER = 'report_card_body_order';

    public const KEY_HEADER_ALIGN = 'report_card_header_align';

    public const KEY_BODY_ALIGN = 'report_card_body_align';

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

    /**
     * Which whole body sections show, and in what order (2026-07-18,
     * "make it much more holistic": scores table, remarks, stamp and
     * signature, etc. are now themselves reorderable/hideable, not just
     * the header/info strip). A section left out is not rendered at all.
     *
     * @return array<int, string>
     */
    public function bodyOrder(): array
    {
        $saved = Setting::grab(self::KEY_BODY_ORDER);
        $valid = array_keys(config('skulos.report_card_body_sections', []));

        return is_array($saved) ? array_values(array_filter($saved, fn ($k) => $this->isValidBodyKey($k))) : $valid;
    }

    /**
     * A fixed catalog key, or a school's own custom text block (user
     * request 2026-07-19: "add option to add text... like address etc"),
     * referenced as "custom_{id}" — see ReportCardCustomBlock::bodyKey().
     * Not looked up against the database here (an id that no longer
     * exists is silently skipped at render time instead, the same
     * orphaned-reference tolerance the rest of this class already has),
     * this only validates the key SHAPE.
     */
    protected function isValidBodyKey(string $key): bool
    {
        return in_array($key, array_keys(config('skulos.report_card_body_sections', [])), true)
            || (bool) preg_match('/^custom_\d+$/', $key);
    }

    /**
     * bodyOrder() when the custom layout is enabled, every section in its
     * original fixed order when it is not (computed here, not in the
     * report card view itself, to sidestep a Blade compiler quirk where a
     * block @php immediately before a @foreach in render.blade.php
     * intermittently failed to compile — see CLAUDE.md's documented
     * single-line/adjacent-directive gotcha; moving this one conditional
     * into PHP proper removed the need for the view to compute it at all).
     *
     * @return array<int, string>
     */
    public function effectiveBodyOrder(bool $enabled): array
    {
        if (! $enabled) {
            return array_keys(config('skulos.report_card_body_sections', []));
        }

        return $this->bodyOrder();
    }

    /**
     * @param array<int, string> $order
     */
    public function setBodyOrder(array $order): void
    {
        Setting::put(self::KEY_BODY_ORDER, array_values(array_filter($order, fn ($k) => $this->isValidBodyKey($k))));
    }

    /**
     * Per header block horizontal position (2026-07-18, user report:
     * "unable to move the logo to left, right or center"). Applies
     * only to the Header zone (a flex column), each block gets its own
     * align-self, independent of its siblings; the Info Strip stays a
     * grid where per-item alignment would not mean much.
     *
     * @return array<string, string> block key => 'left'|'center'|'right'
     */
    public function headerAlign(): array
    {
        $saved = Setting::grab(self::KEY_HEADER_ALIGN);

        return is_array($saved) ? $saved : [];
    }

    /**
     * @param array<string, string> $aligns
     */
    public function setHeaderAlign(array $aligns): void
    {
        $valid = array_keys(config('skulos.report_card_layout_blocks', []));
        $clean = [];
        foreach ($aligns as $key => $align) {
            if (in_array($key, $valid, true) && in_array($align, ['left', 'center', 'right'], true)) {
                $clean[$key] = $align;
            }
        }
        Setting::put(self::KEY_HEADER_ALIGN, $clean);
    }

    /**
     * Per body section horizontal position (2026-07-18, user report:
     * "all these items should be able to move to left, right, or central
     * on the report card page but current one does not allow this"),
     * same mechanism as headerAlign(), a section left at 'stretch' (the
     * default) renders full width exactly as before this existed.
     *
     * @return array<string, string> section key => 'left'|'center'|'right'|'stretch'
     */
    public function bodyAlign(): array
    {
        $saved = Setting::grab(self::KEY_BODY_ALIGN);

        return is_array($saved) ? $saved : [];
    }

    /**
     * @param array<string, string> $aligns
     */
    public function setBodyAlign(array $aligns): void
    {
        $clean = [];
        foreach ($aligns as $key => $align) {
            if ($this->isValidBodyKey($key) && in_array($align, ['left', 'center', 'right', 'stretch'], true)) {
                $clean[$key] = $align;
            }
        }
        Setting::put(self::KEY_BODY_ALIGN, $clean);
    }
}
