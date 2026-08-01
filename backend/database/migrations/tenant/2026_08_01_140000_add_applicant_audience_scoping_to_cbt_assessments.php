<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * CbtAssessment::AUDIENCE_APPLICANT (user directive 2026-08-01: three
 * assessment types, one of them "Admission assessments"): an Applicant
 * isn't enrolled in a class arm the way a Student is, so the existing
 * classArms() targeting (School\User-specific) doesn't apply to this
 * audience at all. applicant_school_class_id is the applicant-audience
 * equivalent — a single SchoolClass an admission assessment is scoped
 * to (e.g. "JSS1 Admission Assessment" only offered to applicants who
 * applied for JSS1), left null to fall open to every applicant, same
 * "no targeting configured = whole audience" default every other
 * audience already has via CbtAssessment::isEligible().
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->foreignId('applicant_school_class_id')->nullable()->after('audience')
                ->constrained('school_classes')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('applicant_school_class_id');
        });
    }
};
