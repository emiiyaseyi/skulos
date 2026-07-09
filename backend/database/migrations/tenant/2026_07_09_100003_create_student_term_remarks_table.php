<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per student, per term remarks that appear on the report card (class
 * teacher remark, head of school remark, next term resumption date).
 * Kept separate from `scores` because these are not per-subject.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_term_remarks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->text('class_teacher_remark')->nullable();
            $table->text('principal_remark')->nullable();
            $table->date('next_term_begins_on')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'term_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_term_remarks');
    }
};
