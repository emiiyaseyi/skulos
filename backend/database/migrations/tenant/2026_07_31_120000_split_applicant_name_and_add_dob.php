<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions module (user directive 2026-07-31, reinforced twice with a
 * screenshot: "split the applicant name on this form, then also request
 * for learner date of birth"). first_name/last_name are real columns now
 * (ApplicantConversionService used to guess the split from one free-text
 * "name" field, an honest best-effort, not real data); `name` itself
 * stays as a real, always-in-sync convenience column (first + last
 * concatenated at write time) rather than removed, since it is already
 * read throughout the module (mail, dashboards, the pipeline board) and
 * a display-only concatenation is one extra field, not a real cost.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('first_name')->nullable()->after('name');
            $table->string('last_name')->nullable()->after('first_name');
            $table->date('date_of_birth')->nullable()->after('last_name');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn(['first_name', 'last_name', 'date_of_birth']);
        });
    }
};
