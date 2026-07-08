<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Configurable grading and results (a core promise of the product: unlimited
 * grading systems and custom CA formats, nothing hardcoded). Tenant only.
 *
 *  - grading_scales / grading_bands: a school defines any number of scales,
 *    each a set of score bands mapping a score range to a grade + remark
 *    (+ optional points for GPA). e.g. 70-100 = A = Excellent.
 *  - assessment_components: the CA format, e.g. "First CA" max 20, "Exam"
 *    max 60. A school can have any components summing to any total.
 *  - scores: one student's score for one component, in one subject, class
 *    arm and term. The subject total is the sum of its component scores;
 *    the grade is looked up from the active scale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('grading_scales', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();
            $table->unique('name');
        });

        Schema::create('grading_bands', function (Blueprint $table) {
            $table->id();
            $table->foreignId('grading_scale_id')->constrained()->cascadeOnDelete();
            $table->decimal('min_score', 6, 2);
            $table->decimal('max_score', 6, 2);
            $table->string('grade');
            $table->string('remark')->nullable();
            $table->decimal('points', 4, 2)->nullable(); // optional GPA points
            $table->timestamps();
            $table->index('grading_scale_id');
        });

        Schema::create('assessment_components', function (Blueprint $table) {
            $table->id();
            $table->string('name');                 // First CA, Exam, ...
            $table->decimal('max_score', 6, 2);     // contributes up to this
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->timestamps();
            $table->unique('name');
        });

        Schema::create('scores', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_arm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('assessment_component_id')->constrained()->cascadeOnDelete();
            $table->decimal('score', 6, 2);
            $table->timestamps();
            $table->unique(['student_id', 'subject_id', 'term_id', 'assessment_component_id'], 'scores_unique');
            $table->index(['class_arm_id', 'subject_id', 'term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('scores');
        Schema::dropIfExists('assessment_components');
        Schema::dropIfExists('grading_bands');
        Schema::dropIfExists('grading_scales');
    }
};
