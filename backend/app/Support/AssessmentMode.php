<?php

namespace App\Support;

/**
 * One place that answers "is proctoring switched on?".
 *
 * Light mode (the default) ships the parts a school actually asked
 * for — a timed attempt, attempt limits, the question bank,
 * randomisation, grading and reporting — and hides everything to do
 * with supervision. The proctoring code is still there and still
 * tested; it is simply not reachable while this returns false.
 *
 * Every screen, route and script that belongs to proctoring asks here
 * rather than checking config directly, so switching modes can never
 * leave one surface half-enabled.
 */
class AssessmentMode
{
    public static function proctoringEnabled(): bool
    {
        return (bool) config('skulos.cbt_proctoring_enabled', false);
    }

    public static function isLight(): bool
    {
        return ! self::proctoringEnabled();
    }

    /**
     * The pre-exam wizard only exists to verify identity and
     * environment, so in light mode a taker goes straight into the
     * attempt.
     */
    public static function precheckRequired(): bool
    {
        return self::proctoringEnabled();
    }
}
