<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKULOS Assessment Hub, Phase 2b: the actual timed test-taking session.
 * `takeable_type`/`takeable_id` is polymorphic on purpose — the exact
 * same session/response engine serves a School\User (student or staff,
 * this sub-phase) and, later, an Applicant (Admissions' own separate
 * identity, no engine changes needed, only Admissions-side wiring — see
 * the Phase 2 plan's own "one engine, multiple use cases" reasoning).
 *
 * cbt_responses' grading columns (is_correct/score_awarded/status/
 * graded_by_user_id/graded_at/notes) are deliberately NOT fillable —
 * same self-approval-footgun closure as AdmissionDocument — mutated
 * only through CbtSessionService's own grading methods or the Phase 2c
 * grading controller's atomic update().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbt_sessions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_id')->constrained('cbt_papers');
            $table->string('takeable_type');
            $table->unsignedBigInteger('takeable_id');
            $table->timestamp('started_at');
            $table->timestamp('ends_at');
            $table->timestamp('submitted_at')->nullable();
            $table->string('status')->default('in_progress');
            $table->decimal('total_score', 8, 2)->nullable();
            $table->decimal('max_score', 8, 2)->nullable();
            $table->timestamps();

            $table->index(['takeable_type', 'takeable_id']);
        });

        Schema::create('cbt_responses', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('cbt_questions');
            $table->json('answer')->nullable();
            $table->boolean('is_correct')->nullable();
            $table->decimal('score_awarded', 6, 2)->nullable();
            $table->string('status')->default('ungraded');
            $table->unsignedBigInteger('graded_by_user_id')->nullable();
            $table->timestamp('graded_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['session_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_responses');
        Schema::dropIfExists('cbt_sessions');
    }
};
