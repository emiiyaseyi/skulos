<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report card review workflow (user request 2026-07-11: "one can either
 * view a report card of a student, generate report card, review it, send
 * it back to the class teacher for review and correction or back to the
 * subject teacher for review and correction... the admin, head teacher,
 * principal or school owner can single or bulk assign some class report
 * to a staff for review before it is released"). A report card itself is
 * still computed live from Score rows via ResultService, this table only
 * tracks its workflow state per student per term, not a stored copy of
 * the marks. assigned_to_staff_id is a plain id, no FK, same style
 * class_teacher_staff_id already uses, so a review can be assigned
 * before the target staff row exists or after it is removed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->string('status')->default('draft');
            $table->unsignedBigInteger('assigned_to_staff_id')->nullable();
            $table->text('notes')->nullable();
            $table->timestamp('released_at')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'term_id']);
            $table->index('assigned_to_staff_id');
        });

        // Optional (user request 2026-07-11: "student and parents can
        // provide feedback to their report card which is optional and
        // not mandatory, don't forget this"). No submission flow exists
        // yet, there is no parent/student portal login at all in the
        // product, this is the data model ready for whenever that is
        // built, see the tracker for that gap.
        Schema::create('report_card_feedbacks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->string('submitted_by'); // 'student' or 'parent'
            $table->text('message');
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_feedbacks');
        Schema::dropIfExists('report_card_reviews');
    }
};
