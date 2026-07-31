<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKULOS Assessment Hub, Phase 2e: a taker whose live face capture
 * doesn't match their uploaded photo ID closely enough after 3 client-
 * side attempts falls back here rather than being hard-blocked (per the
 * reference repo's own behaviour) — a member of staff reviews the two
 * images side by side and approves or rejects manually. Same approval
 * state machine shape as AdmissionDocument/AdmissionPayment: status/
 * reviewed_by_user_id/reviewed_at/notes deliberately NOT fillable,
 * mutated only via the controller's own atomic update().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('cbt_face_approvals', function (Blueprint $table) {
            $table->id();
            $table->foreignId('precheck_session_id')->constrained('cbt_precheck_sessions')->cascadeOnDelete();
            $table->decimal('match_score', 5, 4)->nullable();
            $table->unsignedInteger('attempts')->default(0);
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cbt_face_approvals');
    }
};
