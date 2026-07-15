<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Assignments (Dashboard Design Cross-Check, scope decision 2026-07-15:
 * "Skip Behaviour entirely for now, build Assignments only"). A teacher
 * posts homework/coursework for one subject and one class arm; students
 * and their parents see it, with a due date, on their own dashboards.
 * No submission upload/grading workflow in this pass, a note/task board,
 * not an LMS assignment box (LMS itself is a separate, deferred,
 * Professional tier module).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('assignments', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('description')->nullable();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('class_arm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->nullable()->constrained()->nullOnDelete();
            $table->date('due_on')->nullable();
            $table->foreignId('created_by_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->timestamps();
            $table->index(['class_arm_id', 'due_on']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('assignments');
    }
};
