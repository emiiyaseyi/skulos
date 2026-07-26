<?php

namespace App\Services\ReportCards;

use App\Models\Tenant\Setting;
use App\Models\Tenant\Term;

/**
 * How a term's own report card treats the terms before it in the same
 * session (user request 2026-07-24, Examinations > Term Calculation:
 * "for third term - the school can decide to add first term total and
 * second term total plus the third term total then find the average for
 * each subject... admin can toggle it off if the school decide
 * otherwise... alternatively, the school can decide to add the total
 * from previous terms but it wont find the average, it just display it
 * for comparison purpose only"). Keyed by term SEQUENCE, not a literal
 * "first/second/third" label, so this still makes sense for a school
 * running a different number of terms, or a custom mid session term.
 * Stored in tenant settings, fully admin editable; falls back to
 * config('skulos.default_report_card_cumulative_mode') for any sequence
 * the admin has never explicitly set.
 */
class ReportCardCumulativePolicy
{
    public const KEY = 'report_card_cumulative_policy';

    /**
     * Whether the class restriction below is even in effect (user request
     * 2026-07-26: "per-class-only toggle option" — cumulative calculation
     * makes sense for a school's Primary classes but not necessarily its
     * WAEC/NECO graded Secondary ones, so an admin can restrict it to
     * specific classes only instead of it always applying school wide).
     * Off by default, so an existing school that never touches this keeps
     * its current school wide behaviour unchanged.
     */
    public const KEY_RESTRICTED = 'report_card_cumulative_restricted';

    /** Which classes are included once restricted, empty means every class (same "no rows = every class" convention FeeItem::appliesToClass() already uses). */
    public const KEY_CLASSES = 'report_card_cumulative_class_ids';

    public const MODE_OFF = 'off';

    public const MODE_AVERAGE = 'average';

    public const MODE_COMPARISON = 'comparison';

    /**
     * @return array<int, string> term sequence => mode
     */
    public function all(): array
    {
        $saved = Setting::grab(self::KEY);

        return is_array($saved) ? $saved : [];
    }

    public function isRestrictedToClasses(): bool
    {
        return (bool) Setting::grab(self::KEY_RESTRICTED, false);
    }

    /** @return array<int, int> */
    public function includedClassIds(): array
    {
        $saved = Setting::grab(self::KEY_CLASSES);

        return is_array($saved) ? array_map('intval', $saved) : [];
    }

    /** Unrestricted (the default) means every class; restricted means only a class on the included list. */
    public function appliesToClass(?int $schoolClassId): bool
    {
        if (! $this->isRestrictedToClasses()) {
            return true;
        }

        return $schoolClassId !== null && in_array($schoolClassId, $this->includedClassIds(), true);
    }

    /**
     * The mode this term's own report card should use, for the class the
     * card is being rendered for. A term's sequence is relative to its
     * own session (Term.sequence), so the first term of every session
     * always behaves as 'off' regardless of what is saved, there is
     * nothing earlier in that session to add or compare. A class outside
     * the restriction (when one is set) also always behaves as 'off',
     * regardless of the sequence policy, the same way "before this term
     * even started" does.
     */
    public function modeFor(Term $term, ?int $schoolClassId = null): string
    {
        if ((int) $term->sequence <= 1) {
            return self::MODE_OFF;
        }

        if (! $this->appliesToClass($schoolClassId)) {
            return self::MODE_OFF;
        }

        $saved = $this->all();
        $chosen = $saved[(int) $term->sequence] ?? config('skulos.default_report_card_cumulative_mode', self::MODE_AVERAGE);

        return $this->isValidMode($chosen) ? $chosen : self::MODE_OFF;
    }

    /**
     * @param  array<int, int>  $classIds
     */
    public function setClassRestriction(bool $restricted, array $classIds): void
    {
        Setting::put(self::KEY_RESTRICTED, $restricted);
        Setting::put(self::KEY_CLASSES, array_values(array_unique(array_map('intval', $classIds))));
    }

    /**
     * @param  array<int|string, string>  $bySequence
     */
    public function setPolicy(array $bySequence): void
    {
        $clean = [];
        foreach ($bySequence as $sequence => $mode) {
            $sequence = (int) $sequence;
            if ($sequence > 0 && $this->isValidMode($mode)) {
                $clean[$sequence] = $mode;
            }
        }

        Setting::put(self::KEY, $clean);
    }

    protected function isValidMode(mixed $mode): bool
    {
        return is_string($mode) && array_key_exists($mode, config('skulos.report_card_cumulative_modes', []));
    }
}
