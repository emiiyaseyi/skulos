<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtSchedule;
use App\Models\Tenant\CbtSession;
use Illuminate\Database\Eloquent\Model;

/**
 * Resolves whether a given taker may start an assessment right now,
 * and on what terms. This is the SKULOS equivalent of the reference
 * repo's own `learnguard_effective_window()` / `learnguard_is_available`
 * pair, and it is the ONLY place those rules live — every screen that
 * needs them asks here rather than reimplementing the precedence.
 *
 * Precedence, most specific first:
 *   1. the taker's own CbtSchedule row (window, attempts, duration)
 *   2. the assessment's own opens_at/closes_at, max_attempts, duration
 *
 * Eligibility (is this taker in scope at all) is a separate question,
 * answered by CbtAssessment::isEligible() — an eligible taker can still
 * be outside the window, and a taker inside the window can still be
 * ineligible.
 */
class AssessmentAvailabilityService
{
    public function scheduleFor(CbtAssessment $assessment, Model $taker): ?CbtSchedule
    {
        return CbtSchedule::where('assessment_id', $assessment->id)
            ->where('takeable_type', $taker::class)
            ->where('takeable_id', $taker->getKey())
            ->first();
    }

    /** @return array{opens_at: ?\Illuminate\Support\Carbon, closes_at: ?\Illuminate\Support\Carbon} */
    public function effectiveWindow(CbtAssessment $assessment, Model $taker): array
    {
        $schedule = $this->scheduleFor($assessment, $taker);

        return [
            'opens_at' => $schedule?->opens_at ?? $assessment->opens_at,
            'closes_at' => $schedule?->closes_at ?? $assessment->closes_at,
        ];
    }

    public function effectiveDurationMinutes(CbtAssessment $assessment, Model $taker): int
    {
        return $this->scheduleFor($assessment, $taker)?->duration_override_minutes
            ?? $assessment->duration_minutes;
    }

    public function effectiveMaxAttempts(CbtAssessment $assessment, Model $taker): int
    {
        $extra = $this->scheduleFor($assessment, $taker)?->extra_attempts ?? 0;

        // 0 on the assessment means unlimited, and stays unlimited
        // however many extra attempts are granted on top.
        return $assessment->max_attempts === 0 ? 0 : $assessment->max_attempts + $extra;
    }

    public function shouldSkipPrecheck(CbtAssessment $assessment, Model $taker): bool
    {
        return (bool) $this->scheduleFor($assessment, $taker)?->skip_precheck;
    }

    public function attemptsUsed(CbtAssessment $assessment, Model $taker): int
    {
        return CbtSession::where('assessment_id', $assessment->id)
            ->where('takeable_type', $taker::class)
            ->where('takeable_id', $taker->getKey())
            ->whereIn('status', [
                CbtSession::STATUS_SUBMITTED,
                CbtSession::STATUS_AUTO_SUBMITTED,
                CbtSession::STATUS_GRADED,
            ])->count();
    }

    /**
     * The single question every entry point asks. Returns null when the
     * taker may start, or a human-readable reason why not — phrased for
     * the taker, since that string is shown to them directly.
     */
    public function unavailableReason(CbtAssessment $assessment, Model $taker): ?string
    {
        if (! $assessment->is_active) {
            return 'This assessment is not currently open.';
        }

        if (! $assessment->isEligible($taker)) {
            return 'This assessment has not been assigned to you.';
        }

        ['opens_at' => $opensAt, 'closes_at' => $closesAt] = $this->effectiveWindow($assessment, $taker);

        if ($opensAt && $opensAt->isFuture()) {
            return 'This assessment opens on '.$opensAt->format('j M Y \a\t H:i').'.';
        }
        if ($closesAt && $closesAt->isPast()) {
            return 'This assessment closed on '.$closesAt->format('j M Y \a\t H:i').'.';
        }

        $maxAttempts = $this->effectiveMaxAttempts($assessment, $taker);
        if ($maxAttempts > 0 && $this->attemptsUsed($assessment, $taker) >= $maxAttempts) {
            return 'You have used all '.$maxAttempts.' of your attempts at this assessment.';
        }

        return null;
    }

    public function isAvailable(CbtAssessment $assessment, Model $taker): bool
    {
        return $this->unavailableReason($assessment, $taker) === null;
    }

    /** Whether a taker is allowed to see their own score yet, per the assessment's results policy. */
    public function resultsVisible(CbtAssessment $assessment, CbtSession $session): bool
    {
        if (! $assessment->results_released) {
            return false; // held back explicitly from the console
        }

        return match ($assessment->results_mode) {
            'never' => false,
            'manual' => (bool) $assessment->results_released,
            'after_close' => $assessment->closes_at === null || $assessment->closes_at->isPast(),
            'after_days' => $session->submitted_at !== null
                && $session->submitted_at->addDays($assessment->results_delay_days)->isPast(),
            default => true, // 'immediately'
        };
    }

    /** Checks a submitted PIN against the assessment's own, when it has one. */
    public function pinAccepted(CbtAssessment $assessment, ?string $submitted): bool
    {
        if (! $assessment->access_pin) {
            return true;
        }

        return is_string($submitted) && hash_equals($assessment->access_pin, trim($submitted));
    }
}
