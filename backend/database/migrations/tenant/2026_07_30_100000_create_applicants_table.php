<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions module, Phase 1a (user directive 2026-07-30: rebuild
 * Admissions as an "Admission Pipeline", not a single form). An applicant
 * is deliberately NOT a school_user row: they are not a member of the
 * school until they are actually enrolled, and school_user membership is
 * load-bearing across RBAC/dashboards/rosters throughout this app. This
 * table + its own "applicant" auth guard is a wholly separate identity
 * that only ever produces a real Student + User + school_user row once,
 * at conversion time (ApplicantConversionService, a later sub-phase).
 *
 * pipeline_stage mirrors ReportCardReview's review_stage: an index into
 * an ordered, admin-configurable list of stage keys
 * (Applicant::pipelineChain(), config('skulos.default_admission_pipeline_
 * chain') / tenant Setting 'admission_pipeline_chain'), not a second
 * table of stage rows, the same shape the existing report card review
 * chain already uses successfully.
 *
 * account_status governs whether the applicant can sign in at all
 * (pending_fee -> active once the application fee clears -> archived
 * automatically once expires_at passes, or manually by an admin at any
 * point; locked_out is reserved for a separate manual admin action, e.g.
 * suspected fraud, distinct from an ordinary expiry), kept separate from
 * the admission DECISION itself (offered/rejected/waitlisted/converted,
 * added by a later sub-phase once the pipeline board exists) since these
 * are two different state machines: an applicant can be a genuinely
 * undecided "active" account for the whole pipeline, and a decision does
 * not by itself change whether they can log in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('applicants', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('email')->nullable();
            $table->string('phone')->nullable();
            $table->string('password')->nullable();
            $table->string('admission_number')->nullable();
            $table->foreignId('session_id')->nullable()->constrained('academic_sessions')->nullOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->nullOnDelete();
            $table->foreignId('school_class_id')->nullable()->constrained('school_classes')->nullOnDelete();
            $table->unsignedInteger('pipeline_stage')->default(0);
            $table->string('account_status')->default('pending_fee');
            $table->timestamp('expires_at')->nullable();
            $table->rememberToken();
            $table->timestamps();

            $table->index(['account_status', 'expires_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('applicants');
    }
};
