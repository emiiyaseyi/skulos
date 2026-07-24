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

    /**
     * The mode this term's own report card should use. A term's sequence
     * is relative to its own session (Term.sequence), so the first term
     * of every session always behaves as 'off' regardless of what is
     * saved, there is nothing earlier in that session to add or compare.
     */
    public function modeFor(Term $term): string
    {
        if ((int) $term->sequence <= 1) {
            return self::MODE_OFF;
        }

        $saved = $this->all();
        $chosen = $saved[(int) $term->sequence] ?? config('skulos.default_report_card_cumulative_mode', self::MODE_AVERAGE);

        return $this->isValidMode($chosen) ? $chosen : self::MODE_OFF;
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
