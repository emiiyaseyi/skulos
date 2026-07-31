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

    public function approve(CbtSession $session, int $minutes): void
    {
        abort_unless($session->break_requested, 422, 'No break is pending for this attempt.');

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
