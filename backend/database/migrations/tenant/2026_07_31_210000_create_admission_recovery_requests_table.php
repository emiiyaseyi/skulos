<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions module (user directive 2026-07-31: "what happens if
 * applicant forget the reference number and password... if user did not
 * get any [identity question] correct, they can continue to retry to
 * ask for password reset, they share what they can remember about the
 * applicant... provide an email address and submit, admin review and
 * check if it aligns and then approve which sends a mail with the
 * reference number and password"). The fallback path for when the
 * self-service identity check (Applicant::matchForRecovery(), no table
 * of its own needed) fails — a free-text description an admin reads and
 * manually matches to a real Applicant, not an automated lookup.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_recovery_requests', function (Blueprint $table) {
            $table->id();
            $table->string('remembered_name')->nullable();
            $table->text('description');
            $table->string('email');
            $table->string('status')->default('pending');
            $table->foreignId('matched_applicant_id')->nullable()->constrained('applicants')->nullOnDelete();
            $table->unsignedBigInteger('reviewed_by_user_id')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_recovery_requests');
    }
};
