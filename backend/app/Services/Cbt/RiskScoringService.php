<?php

namespace App\Services\Cbt;

use App\Models\Tenant\CbtSecurityEvent;
use App\Models\Tenant\CbtSession;

/**
 * SKULOS Assessment Hub, Phase 2c: turns a raw proctoring signal into a
 * logged CbtSecurityEvent row and an updated running risk_score/
 * risk_level on the session, matching the reference repo's own
 * medium=30/high=60 thresholds (config('skulos.cbt_risk_thresholds')).
 */
class RiskScoringService
{
    public function recordEvent(CbtSession $session, string $type, ?array $meta = null): CbtSecurityEvent
    {
        $scoreImpact = (int) config("skulos.cbt_security_event_scores.{$type}", 0);

        $event = CbtSecurityEvent::create([
            'session_id' => $session->id,
            'type' => $type,
            'severity' => $this->severityFor($type),
            'score_impact' => $scoreImpact,
            'occurred_at' => now(),
            'meta' => $meta,
        ]);

        $newScore = $session->risk_score + $scoreImpact;
        $updates = [
            'risk_score' => $newScore,
            'risk_level' => $this->levelFor($newScore),
            'is_flagged' => $newScore >= (int) config('skulos.cbt_risk_thresholds.high', 60),
        ];

        if ($type === 'tab_switch') {
            $updates['tab_switches'] = $session->tab_switches + 1;
        } elseif ($type === 'fullscreen_exit') {
            $updates['fullscreen_exits'] = $session->fullscreen_exits + 1;
        } elseif (in_array($type, ['face_not_detected', 'face_mismatch'], true)) {
            $updates['face_missing_count'] = $session->face_missing_count + 1;
        }

        $session->forceFill($updates)->save();

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

    protected function levelFor(int $score): string
    {
        $thresholds = config('skulos.cbt_risk_thresholds', ['medium' => 30, 'high' => 60]);
        if ($score >= $thresholds['high']) {
            return 'high';
        }
        if ($score >= $thresholds['medium']) {
            return 'medium';
        }

        return 'low';
    }
}
