<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assessment Hub, LearnGuard parity: every remaining table from the
 * reference repo's own schema that SKULOS did not model yet, plus the
 * assessment-level policy fields (attempts, grading method, access
 * window, PIN, randomisation, results release) its `learnguard` table
 * carries.
 *
 * Naming follows SKULOS conventions rather than the reference's
 * (cbt_* prefix, snake_case, real FKs, timestamps) — the SHAPE and
 * BEHAVIOUR match, the spelling does not need to.
 */
return new class extends Migration
{
    public function up(): void
    {
        // ── Assessment policy fields ────────────────────────────────
        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->unsignedInteger('max_attempts')->default(1)->after('duration_minutes'); // 0 = unlimited
            $table->string('grade_method')->default('highest')->after('max_attempts'); // highest|average|first|last
            $table->decimal('pass_mark', 8, 2)->nullable()->after('grade_method');
            $table->unsignedInteger('questions_per_page')->default(1)->after('pass_mark');
            $table->boolean('shuffle_questions')->default(false)->after('questions_per_page');
            $table->boolean('randomize')->default(false)->after('shuffle_questions');
            $table->unsignedInteger('num_questions')->default(0)->after('randomize'); // 0 = all
            $table->timestamp('opens_at')->nullable()->after('num_questions');
            $table->timestamp('closes_at')->nullable()->after('opens_at');
            $table->string('access_pin', 20)->nullable()->after('closes_at');
            $table->string('results_mode')->default('immediately')->after('access_pin'); // immediately|after_close|after_days|never|manual
            $table->unsignedInteger('results_delay_days')->default(0)->after('results_mode');
        });

        // ── Proctors: who may invigilate, scoped school-wide or to one assessment ──
        Schema::connection('tenant')->create('cbt_proctors', function (Blueprint $table) {
            $table->id();
            // Null assessment_id = a school-wide proctor for every assessment.
            $table->foreignId('assessment_id')->nullable()->constrained('cbt_assessments')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('proctor');
            $table->timestamps();

            $table->unique(['assessment_id', 'user_id']);
        });

        // ── Graders: two-layer, optionally anonymous ────────────────
        Schema::connection('tenant')->create('cbt_graders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->nullable()->constrained('cbt_assessments')->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            // Anonymous graders never see who wrote an answer — the
            // taker shows as "Candidate #<session id>".
            $table->boolean('anonymous')->default(false);
            // 1 = first grader, 2 = second review (can override layer 1).
            $table->unsignedTinyInteger('grade_layer')->default(1);
            $table->timestamp('deadline')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'user_id', 'grade_layer'], 'cbt_graders_unique');
        });

        // ── How essays are shared out among layer-1 graders ─────────
        Schema::connection('tenant')->create('cbt_grading_configs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('cbt_assessments')->cascadeOnDelete();
            $table->string('mode')->default('all'); // all|split|random|manual
            $table->unsignedInteger('cap')->default(0); // max attempts per grader, 0 = even split
            $table->timestamps();

            $table->unique('assessment_id');
        });

        Schema::connection('tenant')->create('cbt_grader_assignments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('cbt_assessments')->cascadeOnDelete();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->unsignedBigInteger('grader_user_id');
            $table->timestamps();

            $table->unique(['assessment_id', 'session_id'], 'cbt_grader_assign_unique');
        });

        /**
         * Full grade history per response per grader. The live score
         * lives on cbt_responses; this keeps every grader's own figure
         * so a layer-2 override never destroys what layer 1 recorded,
         * which is what makes the grade-history popup possible.
         */
        Schema::connection('tenant')->create('cbt_essay_grades', function (Blueprint $table) {
            $table->id();
            $table->foreignId('response_id')->constrained('cbt_responses')->cascadeOnDelete();
            $table->unsignedBigInteger('grader_user_id');
            $table->unsignedTinyInteger('grade_layer')->default(1);
            $table->decimal('score', 8, 2)->default(0);
            $table->text('feedback')->nullable();
            $table->timestamps();

            $table->index(['response_id', 'grade_layer']);
        });

        // ── Question pools: draw N at random from a category ────────
        Schema::connection('tenant')->create('cbt_question_pools', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('cbt_assessments')->cascadeOnDelete();
            $table->foreignId('category_id')->constrained('cbt_question_categories')->cascadeOnDelete();
            $table->unsignedInteger('draw_count')->default(1);
            $table->decimal('points_each', 8, 2)->default(1);
            $table->unsignedInteger('sequence')->default(0);
            $table->timestamps();
        });

        /**
         * The exact question set THIS attempt was given, in THIS
         * taker's own order. Without it a randomised or shuffled
         * assessment could not be re-rendered identically on resume,
         * nor reviewed afterwards.
         */
        Schema::connection('tenant')->create('cbt_session_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('cbt_questions')->cascadeOnDelete();
            $table->unsignedInteger('sequence');
            $table->decimal('points', 8, 2)->default(1);
            $table->timestamps();

            $table->unique(['session_id', 'sequence']);
        });

        // ── Audio clips captured when the room gets too loud ────────
        Schema::connection('tenant')->create('cbt_audio_clips', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->string('path');
            $table->unsignedInteger('duration_seconds')->default(0);
            $table->unsignedInteger('noise_level')->default(0);
            $table->timestamp('captured_at');
            $table->timestamps();

            $table->index('session_id');
        });

        // ── In-exam chat between taker and invigilator ──────────────
        Schema::connection('tenant')->create('cbt_chat_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('cbt_sessions')->cascadeOnDelete();
            $table->string('sender_type')->default('taker'); // taker|proctor
            $table->unsignedBigInteger('sender_user_id')->nullable();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index(['session_id', 'created_at']);
        });

        // ── Chat while waiting on a face-approval decision ──────────
        Schema::connection('tenant')->create('cbt_face_approval_messages', function (Blueprint $table) {
            $table->id();
            $table->foreignId('face_approval_id')->constrained('cbt_face_approvals')->cascadeOnDelete();
            $table->string('sender_type')->default('taker');
            $table->unsignedBigInteger('sender_user_id')->nullable();
            $table->text('body');
            $table->timestamps();

            $table->index('face_approval_id');
        });

        /**
         * Per-taker overrides: a personal window, extra attempts, a
         * different time limit, or permission to skip the precheck
         * wizard. This is what the Scheduling, Bulk Scheduling and
         * Assessment Manager screens all write to.
         */
        Schema::connection('tenant')->create('cbt_schedules', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('cbt_assessments')->cascadeOnDelete();
            $table->string('takeable_type');
            $table->unsignedBigInteger('takeable_id');
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->unsignedInteger('extra_attempts')->default(0);
            $table->unsignedInteger('duration_override_minutes')->nullable();
            $table->boolean('skip_precheck')->default(false);
            $table->unsignedBigInteger('created_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'takeable_type', 'takeable_id'], 'cbt_schedules_unique');
        });
    }

    public function down(): void
    {
        foreach ([
            'cbt_schedules', 'cbt_face_approval_messages', 'cbt_chat_messages', 'cbt_audio_clips',
            'cbt_session_questions', 'cbt_question_pools', 'cbt_essay_grades', 'cbt_grader_assignments',
            'cbt_grading_configs', 'cbt_graders', 'cbt_proctors',
        ] as $table) {
            Schema::connection('tenant')->dropIfExists($table);
        }

        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->dropColumn([
                'max_attempts', 'grade_method', 'pass_mark', 'questions_per_page', 'shuffle_questions',
                'randomize', 'num_questions', 'opens_at', 'closes_at', 'access_pin',
                'results_mode', 'results_delay_days',
            ]);
        });
    }
};
