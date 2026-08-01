<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtSession;

/**
 * Invigilator control over a running attempt, matching the reference
 * repo's own Admin Backend actions: suspend, reinstate, force-submit,
 * and an emergency pause/resume that freezes a whole assessment at
 * once without ending anybody's attempt.
 *
 * Suspension is deliberately NOT submission: a suspended attempt has
 * no score and can be put back, which is exactly why it needs its own
 * state rather than reusing auto_submitted.
 */
class AttemptControlService
{
    public function suspend(CbtSession $session, int $byUserId, ?string $reason = null): void
    {
        abort_unless($session->status === CbtSession::STATUS_IN_PROGRESS, 422, 'Only an attempt in progress can be suspended.');

        $session->forceFill([
            'status' => CbtSession::STATUS_SUSPENDED,
            'suspended_at' => now(),
            'suspended_by_user_id' => $byUserId,
            'suspended_reason' => $reason,
        ])->save();
    }

    /**
     * Puts a suspended attempt back in progress. The remaining time is
     * restored by pushing `ends_at` forward by however long the
     * suspension lasted, so a taker is not punished twice for staff
     * deliberation time.
     */
    public function reinstate(CbtSession $session): void
    {
        abort_unless($session->status === CbtSession::STATUS_SUSPENDED, 422, 'That attempt is not suspended.');

        $lostSeconds = $session->suspended_at ? $session->suspended_at->diffInSeconds(now()) : 0;

        $session->forceFill([
            'status' => CbtSession::STATUS_IN_PROGRESS,
            'ends_at' => $session->ends_at->addSeconds($lostSeconds),
            'suspended_at' => null,
            'suspended_by_user_id' => null,
            'suspended_reason' => null,
        ])->save();
    }

    /** Ends an attempt on the taker's behalf, grading whatever they had answered. */
    public function forceSubmit(CbtSession $session, CbtSessionService $sessions): void
    {
        abort_unless($session->status === CbtSession::STATUS_IN_PROGRESS, 422, 'That attempt is not in progress.');

        $sessions->submit($session, CbtSession::STATUS_AUTO_SUBMITTED);
    }

    /** Freezes every in-progress attempt on an assessment; the take screen's own timer stops while paused. */
    public function pauseAssessment(CbtAssessment $assessment): void
    {
        $assessment->forceFill(['paused_at' => now()])->save();
    }

    /**
     * Resumes a paused assessment, giving every attempt that was live
     * during the pause the paused time back — same reasoning as
     * reinstate(): staff-caused downtime never costs exam time.
     */
    public function resumeAssessment(CbtAssessment $assessment): void
    {
        if (! $assessment->paused_at) {
            return;
        }

        $pausedSeconds = $assessment->paused_at->diffInSeconds(now());

        $assessment->sessions()
            ->where('status', CbtSession::STATUS_IN_PROGRESS)
            ->each(function (CbtSession $session) use ($pausedSeconds) {
                $session->forceFill(['ends_at' => $session->ends_at->addSeconds($pausedSeconds)])->save();
            });

        $assessment->forceFill(['paused_at' => null])->save();
    }
}
