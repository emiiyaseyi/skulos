<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A subject teacher's remark on one student's result in one subject, for
 * one term (user directive 2026-07-12, "Intelligent Assessment & Report
 * Card Workflow.docx"'s "Subject Teacher Comments" concept). Kept
 * separate from `scores` (which is per assessment component, this is
 * per subject) and from `student_term_remarks` (which is per term, not
 * per subject, used for the class teacher/principal overall remark).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('subject_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_arm_id')->constrained()->cascadeOnDelete();
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'subject_id', 'term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subject_remarks');
    }
};
