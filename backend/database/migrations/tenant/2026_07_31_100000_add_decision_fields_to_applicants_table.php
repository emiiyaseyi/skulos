<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions module, Phase 1g. The admission DECISION (offered/rejected/
 * waitlisted/converted), a separate state machine from Applicant::
 * account_status (whether the applicant can sign in at all) — an
 * applicant can be a genuinely undecided "active" account for the whole
 * pipeline, and a decision does not by itself change whether they can
 * log in (see the applicants table's own creating migration docblock,
 * same reasoning).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('decision_status')->nullable()->after('pipeline_stage');
            $table->unsignedBigInteger('decided_by_user_id')->nullable()->after('decision_status');
            $table->timestamp('decided_at')->nullable()->after('decided_by_user_id');
            $table->string('decision_notes', 500)->nullable()->after('decided_at');
            $table->foreignId('converted_student_id')->nullable()->after('decision_notes')->constrained('students')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('converted_student_id');
            $table->dropColumn(['decision_status', 'decided_by_user_id', 'decided_at', 'decision_notes']);
        });
    }
};
