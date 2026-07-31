<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKULOS Assessment Hub, Phase 2a: the question bank, paper builder, and
 * security profile catalog — the admin/teacher-facing side, no
 * test-taking yet (that's 2b). "Cbt" (Computer-Based Test) is the model
 * prefix, deliberately distinct from the pre-existing AssessmentComponent/
 * AssessmentController (the CA-vs-Exam weighting concept for report
 * cards, a different, older feature this must not collide with).
 *
 * cbt_papers.security_profile_id points at one of four seeded profiles
 * (Basic/Medium/High/Maximum, see CbtSecurityProfile::ensureSeeded()) —
 * the four-tier concept itself is fixed by the original spec, only each
 * tier's individual flags are admin-editable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cbt_security_profiles', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('require_fullscreen')->default(false);
            $table->boolean('block_copy_paste')->default(false);
            $table->boolean('block_right_click')->default(false);
            $table->string('tab_switch_action')->default('none');
            $table->unsignedInteger('tab_switch_limit')->nullable();
            $table->boolean('require_camera_check')->default(false);
            $table->boolean('require_object_detection')->default(false);
            $table->timestamps();
        });

        Schema::create('cbt_questions', function (Blueprint $table) {
            $table->id();
            $table->string('type');
            $table->text('prompt');
            $table->json('options')->nullable();
            $table->json('correct_answer')->nullable();
            $table->decimal('points', 6, 2)->default(1);
            $table->string('image_path')->nullable();
            $table->string('category')->nullable();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cbt_papers', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('instructions')->nullable();
            $table->unsignedInteger('duration_minutes');
            $table->foreignId('security_profile_id')->constrained('cbt_security_profiles');
            $table->string('audience')->default('student');
            $table->boolean('requires_double_grading')->default(false);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('cbt_paper_questions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('paper_id')->constrained('cbt_papers')->cascadeOnDelete();
            $table->foreignId('question_id')->constrained('cbt_questions')->cascadeOnDelete();
            $table->unsignedInteger('sequence')->default(0);
            $table->decimal('points_override', 6, 2)->nullable();
            $table->timestamps();

            $table->unique(['paper_id', 'question_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cbt_paper_questions');
        Schema::dropIfExists('cbt_papers');
        Schema::dropIfExists('cbt_questions');
        Schema::dropIfExists('cbt_security_profiles');
    }
};
