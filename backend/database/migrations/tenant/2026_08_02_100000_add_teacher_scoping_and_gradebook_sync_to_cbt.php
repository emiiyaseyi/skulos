<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two user directives, 2026-08-02, landed together since both touch
 * cbt_assessments:
 *
 * 1. "class teacher and subject teacher can set assessment for the
 *    classes assigned to them" — a scoped teacher's assessments are now
 *    restricted (server-side, not just hidden in the UI) to their own
 *    TeachingAssignment subjects/class arms, same pattern
 *    ExaminationController::allowedPairs() already uses for score
 *    entry. created_by_staff_id is the audit trail that also makes the
 *    assessments INDEX itself scopeable (a non-blanket teacher sees
 *    only what they created), not just the builder's own subject/class
 *    pickers.
 *
 * 2. "class teacher and subject teacher can decide that the assessment
 *    should be used for a continuous assessment or examination" — a
 *    class assessment (audience=student only) can optionally link one
 *    real AssessmentComponent (the same school-wide CA/Exam catalog
 *    score entry already writes to). gradebook_sync_mode is chosen by
 *    the teacher per assessment, at setup time (confirmed with the
 *    user, not a global setting): 'auto' writes the scaled score into
 *    cbt_assessments the instant a session grades, overwriting any
 *    existing manual score there; 'manual' (the default, the safer
 *    choice for a teacher who links a component without thinking about
 *    sync mode) queues it for an explicit review-and-push instead. See
 *    App\Services\Cbt\GradebookSyncService.
 *
 * gradebook_synced_at on cbt_sessions is what lets that service tell
 * "already synced" apart from "still waiting", and lets a manual-mode
 * review list filter to only what still needs a decision.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->foreignId('created_by_staff_id')->nullable()->after('audience')
                ->constrained('staff')->nullOnDelete();
            $table->foreignId('assessment_component_id')->nullable()->after('term_id')
                ->constrained('assessment_components')->nullOnDelete();
            $table->string('gradebook_sync_mode')->default('manual')->after('assessment_component_id');
        });

        Schema::connection('tenant')->table('cbt_sessions', function (Blueprint $table) {
            $table->timestamp('gradebook_synced_at')->nullable()->after('total_score');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('cbt_sessions', function (Blueprint $table) {
            $table->dropColumn('gradebook_synced_at');
        });

        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_staff_id');
            $table->dropConstrainedForeignId('assessment_component_id');
            $table->dropColumn('gradebook_sync_mode');
        });
    }
};
