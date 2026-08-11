<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-triggered student promotion (feedback list 8/7/2026, item 9), a
 * second, separate path alongside the existing automatic
 * PromotionService::promote() that runs at end-of-session. That
 * automatic path stays fully intact and untouched; this table records a
 * real, persisted, revisitable report every time an admin runs this new
 * manual flow — the user explicitly wants "a report of all those that
 * were moved per class... a list of those not moved and reason for not
 * being moved," which only survives if it is written down, not derived
 * live each time (a promoted student's old class arm is only ever
 * recoverable from a record like this one).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('promotion_runs', function (Blueprint $table) {
            $table->id();
            // The source term whose results were evaluated.
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            // A label only ("promoted into X") — does not itself change
            // the school's current term/session, that stays End Term's
            // own job via Term::makeCurrent()/AcademicSession::makeCurrent().
            $table->foreignId('to_term_id')->nullable()->constrained('terms')->nullOnDelete();
            $table->float('threshold');
            // Platform users.id, no cross-connection foreign key (same
            // reasoning ProfileChangeRequest.user_id already documents).
            $table->unsignedBigInteger('run_by_user_id');
            $table->unsignedInteger('promoted_count')->default(0);
            $table->unsignedInteger('repeated_count')->default(0);
            $table->unsignedInteger('graduated_count')->default(0);
            $table->unsignedInteger('skipped_count')->default(0);
            $table->timestamps();
        });

        Schema::create('promotion_run_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('promotion_run_id')->constrained()->cascadeOnDelete();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('from_class_arm_id')->constrained('class_arms')->cascadeOnDelete();
            $table->foreignId('to_class_arm_id')->nullable()->constrained('class_arms')->nullOnDelete();
            $table->float('average')->nullable();
            $table->string('outcome');
            $table->string('reason', 255)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('promotion_run_items');
        Schema::dropIfExists('promotion_runs');
    }
};
