<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtIncident;
use App\Models\Tenant\CbtSecurityEvent;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\CbtSession;

/**
 * SKULOS Assessment Hub, Phase 2c: turns a raw proctoring signal into a
 * logged CbtSecurityEvent row and an updated running risk_score/
 * risk_level on the session. As of 2026-08-12 (feedback list 8/7/2026
 * item 18, in line with the LearnGuard reference plugin's own
 * per-profile risk weights/thresholds/auto-suspend), the session's own
 * CbtSecurityProfile is the real source of truth for event weights,
 * thresholds, and an optional auto-suspend score — config('skulos.
 * cbt_security_event_scores'/'cbt_risk_thresholds') is now only a
 * defensive fallback for the rare case a session's assessment has no
 * profile at all.
 */
class RiskScoringService
{
    public function __construct(protected AttemptControlService $attempts)
    {
    }

    public function recordEvent(CbtSession $session, string $type, ?array $meta = null): CbtSecurityEvent
    {
        $profile = $session->assessment?->securityProfile;
        $scoreImpact = (int) ($profile?->event_scores[$type] ?? config("skulos.cbt_security_event_scores.{$type}", 0));

        $event = CbtSecurityEvent::create([
            'session_id' => $session->id,
            'type' => $type,
            'severity' => $this->severityFor($type),
            'score_impact' => $scoreImpact,
            'occurred_at' => now(),
            'meta' => $meta,
        ]);

        $newScore = $session->risk_score + $scoreImpact;
        $wasFlagged = $session->is_flagged;
        $highThreshold = $profile?->risk_high_threshold ?? (int) config('skulos.cbt_risk_thresholds.high', 60);
        $nowFlagged = $newScore >= $highThreshold;
        $updates = [
            'risk_score' => $newScore,
            'risk_level' => $this->levelFor($newScore, $profile),
            'is_flagged' => $nowFlagged,
        ];

        // Phase 2g: the FIRST time a session crosses into "high" risk,
        // raise an incident for staff to review from the live monitor —
        // never re-raised on every subsequent high-risk event, only the
        // crossing itself.
        if ($nowFlagged && ! $wasFlagged) {
            CbtIncident::create([
                'session_id' => $session->id,
                'type' => 'high_risk_crossing',
                'severity' => 'high',
                'description' => "Risk score crossed the high threshold ({$newScore}).",
            ]);
        }

        if ($type === 'tab_switch') {
            $updates['tab_switches'] = $session->tab_switches + 1;
        } elseif ($type === 'fullscreen_exit') {
            $updates['fullscreen_exits'] = $session->fullscreen_exits + 1;
        } elseif (in_array($type, ['face_not_detected', 'face_mismatch'], true)) {
            $updates['face_missing_count'] = $session->face_missing_count + 1;
        }

        $session->forceFill($updates)->save();

        // A real new capability (item 18): a profile with an
        // autosuspend_score set actually suspends the attempt once the
        // risk score reaches it, rather than only quietly raising an
        // incident a proctor might not see in time. A profile that
        // leaves this null (every tier except Maximum, by default)
        // behaves exactly as before — auto-suspend is opt-in per tier.
        if ($profile?->autosuspend_score !== null
            && $newScore >= $profile->autosuspend_score
            && $session->status === CbtSession::STATUS_IN_PROGRESS) {
            $this->attempts->suspend(
                $session,
                null,
                "Automatically suspended: risk score reached the configured auto-suspend threshold ({$profile->autosuspend_score})."
            );
        }

        return $event;
    }

    protected function severityFor(string $type): string
    {
        return match ($type) {
            'object_detected', 'face_mismatch' => 'high',
            'fullscreen_exit', 'tab_switch', 'face_not_detected' => 'warning',
            default => 'info',
        };
    }

    protected function levelFor(int $score, ?CbtSecurityProfile $profile): string
    {
        $medium = $profile?->risk_medium_threshold ?? (int) config('skulos.cbt_risk_thresholds.medium', 30);
        $high = $profile?->risk_high_threshold ?? (int) config('skulos.cbt_risk_thresholds.high', 60);

        if ($score >= $high) {
            return 'high';
        }
        if ($score >= $medium) {
            return 'medium';
        }

        return 'low';
    }
}
