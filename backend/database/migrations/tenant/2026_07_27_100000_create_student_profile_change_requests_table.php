<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A parent's requested change to their child's profile (user request
 * 2026-07-26: "parent should be able to edit their child details
 * (everything there except admission number, class and house) - class
 * teacher has to approve the changes first then the school admin also
 * have to do the final approval before it takes effect"). Two real,
 * sequential decisions, not the flat single-approver shape
 * ProfileChangeRequest already uses for a member's own name/email/phone,
 * so this is its own table rather than reusing that one.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_profile_change_requests', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            // Platform users.id, no cross-connection foreign key (same
            // reasoning ProfileChangeRequest.user_id already documents).
            $table->unsignedBigInteger('submitted_by_user_id');
            $table->json('original');
            $table->json('requested');
            $table->string('status');
            // The specific class teacher this request was routed to,
            // resolved once at submission time from the student's class
            // arm at that moment, not re-resolved later if the student
            // changes class or the class teacher assignment changes
            // mid-review.
            $table->foreignId('class_teacher_staff_id')->nullable()->constrained('staff')->nullOnDelete();
            $table->unsignedBigInteger('class_teacher_decided_by_user_id')->nullable();
            $table->timestamp('class_teacher_decided_at')->nullable();
            $table->string('class_teacher_notes', 500)->nullable();
            $table->unsignedBigInteger('admin_decided_by_user_id')->nullable();
            $table->timestamp('admin_decided_at')->nullable();
            $table->string('admin_notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_profile_change_requests');
    }
};
