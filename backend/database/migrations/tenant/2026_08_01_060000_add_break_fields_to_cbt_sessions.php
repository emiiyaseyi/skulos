<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKULOS Assessment Hub, Phase 2g: a taker can request a break mid-
 * attempt (staff approves/denies from the live monitor). Deliberately
 * three states from two columns rather than a bool + a status string:
 * break_requested=true/break_started_at=null is "pending"; a non-null
 * break_started_at is "approved and running" (break_ends_at is when it
 * lifts); both null/false is "no break in play". Approving a break also
 * pushes the session's own `ends_at` forward by the break length, so
 * break time is never silently deducted from the taker's exam time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('cbt_sessions', function (Blueprint $table) {
            $table->boolean('break_requested')->default(false)->after('risk_level');
            $table->string('break_reason')->nullable()->after('break_requested');
            $table->timestamp('break_started_at')->nullable()->after('break_reason');
            $table->timestamp('break_ends_at')->nullable()->after('break_started_at');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('cbt_sessions', function (Blueprint $table) {
            $table->dropColumn(['break_requested', 'break_reason', 'break_started_at', 'break_ends_at']);
        });
    }
};
