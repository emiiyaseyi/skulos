<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtSession;

/**
 * SKULOS Assessment Hub, Phase 2g: a taker's break request, decided by
 * a member of staff from the live monitor. Approving pushes the
 * session's own `ends_at` forward by the same number of minutes, so a
 * taker never loses exam time to an approved break.
 */
class BreakRequestService
{
    public function request(CbtSession $session, string $reason): void
    {
        abort_unless($session->status === CbtSession::STATUS_IN_PROGRESS, 422, 'This attempt is not in progress.');
        abort_if($session->break_requested || $session->isOnBreak(), 422, 'A break has already been requested or is already running.');

        $session->forceFill(['break_requested' => true, 'break_reason' => $reason])->save();
    }

    /**
     * $minutes is the staff-supplied duration, clamped into the
     * session's own security profile break_min_minutes/break_max_minutes
     * when the profile sets them (item 18, 2026-08-12) — null on either
     * side (every tier except Maximum by default) leaves that side
     * unclamped, matching today's fully ad hoc behavior exactly.
     */
    public function approve(CbtSession $session, int $minutes): void
    {
        abort_unless($session->break_requested, 422, 'No break is pending for this attempt.');

        $profile = $session->assessment?->securityProfile;
        if ($profile?->break_min_minutes !== null) {
            $minutes = max($minutes, $profile->break_min_minutes);
        }
        if ($profile?->break_max_minutes !== null) {
            $minutes = min($minutes, $profile->break_max_minutes);
        }

        $session->forceFill([
            'break_requested' => false,
            'break_started_at' => now(),
            'break_ends_at' => now()->addMinutes($minutes),
            'ends_at' => $session->ends_at->addMinutes($minutes),
        ])->save();
    }

    public function deny(CbtSession $session): void
    {
        abort_unless($session->break_requested, 422, 'No break is pending for this attempt.');

        $session->forceFill(['break_requested' => false, 'break_reason' => null])->save();
    }
}
