<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real two-way message thread between school staff and an applicant
 * on the admissions pipeline (user directive 2026-09-09: "add a option
 * for schools admins to send a message to applicants and for applicants
 * to also send back to them"). Deliberately separate from the existing
 * one-way AdmissionReminderMail "nudge" (a canned, unlogged email) —
 * this is a genuinely readable, appendable thread both sides can see.
 *
 * `sender_user_id` is a plain unsigned column, not a foreign key: it
 * references a platform-database User (the staff member who sent it),
 * a cross-connection reference this codebase already handles the same
 * way elsewhere (Applicant.decided_by_user_id) rather than a real FK,
 * since the tenant and platform databases are physically separate.
 * Null when sender = 'applicant'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_messages', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('applicant_id');
            $table->string('sender', 20);
            $table->unsignedBigInteger('sender_user_id')->nullable();
            $table->text('body');
            $table->timestamp('read_at')->nullable();
            $table->timestamps();

            $table->index('applicant_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_messages');
    }
};
