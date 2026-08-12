<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment Security Profiles redesign, in line with the LearnGuard
 * reference plugin's own settings catalog (feedback list 8/7/2026, item
 * 18): per-event risk WEIGHTS, per-profile risk THRESHOLDS, a real
 * auto-suspend score, and break min/max minutes — all previously flat,
 * global, non-admin-editable config (or, for auto-suspend, not a real
 * capability at all).
 *
 * Every already-provisioned school's existing 4 profiles are backfilled
 * here, in the same migration, to today's real global values —
 * CbtSecurityProfile::ensureSeeded() only ever seeds a school's FIRST 4
 * rows once, so relying on config('skulos.default_cbt_security_profiles')
 * alone would silently skip every school that already has its profiles.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_security_profiles', function (Blueprint $table) {
            $table->json('event_scores')->nullable()->after('snapshot_interval_seconds');
            $table->unsignedInteger('risk_medium_threshold')->default(30)->after('event_scores');
            $table->unsignedInteger('risk_high_threshold')->default(60)->after('risk_medium_threshold');
            $table->unsignedInteger('autosuspend_score')->nullable()->after('risk_high_threshold');
            $table->unsignedInteger('break_min_minutes')->nullable()->after('autosuspend_score');
            $table->unsignedInteger('break_max_minutes')->nullable()->after('break_min_minutes');
        });

        $eventScores = json_encode(config('skulos.cbt_security_event_scores', [
            'tab_switch' => 10, 'fullscreen_exit' => 15, 'copy_attempt' => 5, 'paste_attempt' => 5,
            'right_click' => 2, 'window_blur' => 5, 'face_not_detected' => 8, 'face_mismatch' => 20,
            'object_detected' => 25, 'noise_threshold' => 5,
        ]));

        // Identical for every already-seeded tier, matching how every
        // profile shares one global table today — a school can diverge
        // per-tier later, this migration only preserves current behavior.
        DB::table('cbt_security_profiles')->update([
            'event_scores' => $eventScores,
            'risk_medium_threshold' => config('skulos.cbt_risk_thresholds.medium', 30),
            'risk_high_threshold' => config('skulos.cbt_risk_thresholds.high', 60),
        ]);
    }

    public function down(): void
    {
        Schema::table('cbt_security_profiles', function (Blueprint $table) {
            $table->dropColumn(['event_scores', 'risk_medium_threshold', 'risk_high_threshold', 'autosuspend_score', 'break_min_minutes', 'break_max_minutes']);
        });
    }
};
