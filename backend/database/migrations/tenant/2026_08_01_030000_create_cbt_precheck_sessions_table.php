<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKULOS Assessment Hub, Phase 2d: the 15-step pre-exam wizard's own
 * progress record. Deliberately separate from `cbt_sessions` (not just
 * a few extra columns on it) — a precheck can be abandoned or retried
 * before any timed attempt exists at all, and its own `takeable`
 * polymorphic pair mirrors `cbt_sessions`' exact same shape for the
 * exact same reason (see that migration's own docblock). The real
 * CbtSession this precheck feeds is only ever created once, at the
 * 'ready' step's completion (`cbt_session_id`, set then).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('cbt_precheck_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_id')->constrained('cbt_papers')->cascadeOnDelete();
            $table->string('takeable_type');
            $table->unsignedBigInteger('takeable_id');
            $table->foreignId('cbt_session_id')->nullable()->constrained('cbt_sessions')->nullOnDelete();
            $table->string('current_step')->default('welcome');
            $table->json('steps_completed')->nullable();
            $table->string('photo_id_path')->nullable();
            $table->string('face_capture_path')->nullable();
            $table->string('environment_video_path')->nullable();
            $table->json('device_checks')->nullable();
            $table->string('status')->default('in_progress');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->index(['takeable_type', 'takeable_id']);
            $table->index(['paper_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cbt_precheck_sessions');
    }
};
