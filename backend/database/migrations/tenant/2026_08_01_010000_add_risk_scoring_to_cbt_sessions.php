<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKULOS Assessment Hub, Phase 2c: risk scoring + a unified security
 * event log, matching LearnGuard's own learnguard_attempts risk columns
 * and learnguard_events table (see the Phase 2 plan's reference-repo
 * research). Every proctoring signal — JS-only tab-switch/fullscreen/
 * copy-paste detection (this sub-phase, wired onto the take screen
 * already built in 2b) and AI-based checks (2e) — writes here.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_sessions', function (Blueprint $table) {
            $table->string('ip_address')->nullable()->after('takeable_id');
            $table->string('browser_info')->nullable()->after('ip_address');
            $table->boolean('is_flagged')->default(false)->after('status');
            $table->unsignedInteger('tab_switches')->default(0)->after('is_flagged');
            $table->unsignedInteger('fullscreen_exits')->default(0)->after('tab_switches');
            $table->unsignedInteger('face_missing_count')->default(0)->after('fullscreen_exits');
            $table->unsignedInteger('risk_score')->default(0)->after('face_missing_count');
            $table->string('risk_level')->default('low')->after('risk_score');
        });

        Schema::create('cbt_security_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->string('type');
            $table->string('severity')->default('info');
            $table->unsignedInteger('score_impact')->default(0);
            $table->timestamp('occurred_at');
            $table->json('meta')->nullable();
            $table->boolean('overridden')->default(false);
            $table->timestamps();

            $table->index(['session_id', 'type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_security_events');
        Schema::table('cbt_sessions', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'browser_info', 'is_flagged', 'tab_switches', 'fullscreen_exits', 'face_missing_count', 'risk_score', 'risk_level']);
        });
    }
};
