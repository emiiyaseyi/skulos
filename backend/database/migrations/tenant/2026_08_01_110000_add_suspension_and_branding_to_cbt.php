<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment Hub, LearnGuard parity: the Admin Backend console needs
 * two things SKULOS did not model yet.
 *
 * 1. A SUSPENDED attempt state. The reference's own attempt state
 *    machine is inprogress|finished|suspended — a suspended attempt is
 *    one an invigilator (or the auto-suspend risk threshold) has
 *    stopped, which is NOT the same as a submitted one: it has no
 *    score, and it can be REINSTATED back to in-progress. Modelled as
 *    a new CbtSession::STATUS_SUSPENDED plus who/when/why, so the
 *    console's "Suspended" tab can show and reverse it.
 *    Also adds paused_at, for the reference's own "Emergency Pause ALL
 *    students" action, which freezes a whole assessment at once
 *    without ending anybody's attempt.
 *
 * 2. Per-assessment branding (the console's own Branding tab): an
 *    assessment can override the school's colours and display name on
 *    the take screen, e.g. for an external/entrance exam that should
 *    not carry the school's normal chrome.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('cbt_sessions', function (Blueprint $table) {
            $table->timestamp('suspended_at')->nullable()->after('break_ends_at');
            $table->unsignedBigInteger('suspended_by_user_id')->nullable()->after('suspended_at');
            $table->string('suspended_reason', 500)->nullable()->after('suspended_by_user_id');
            $table->timestamp('last_seen_at')->nullable()->after('suspended_reason');
        });

        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->timestamp('paused_at')->nullable()->after('is_active');
            $table->string('brand_color', 20)->nullable()->after('paused_at');
            $table->string('brand_name')->nullable()->after('brand_color');
            // Results held back until staff explicitly release them —
            // the reference's own "Results Pending" state on the
            // student's own result screen.
            $table->boolean('results_released')->default(true)->after('brand_name');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('cbt_sessions', function (Blueprint $table) {
            $table->dropColumn(['suspended_at', 'suspended_by_user_id', 'suspended_reason', 'last_seen_at']);
        });
        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->dropColumn(['paused_at', 'brand_color', 'brand_name', 'results_released']);
        });
    }
};
