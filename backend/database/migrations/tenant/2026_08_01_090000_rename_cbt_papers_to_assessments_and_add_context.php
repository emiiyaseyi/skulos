<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKULOS Assessment Hub rework (user directive 2026-07-31, live:
 * "RECHECK AGAIN, I DID NOT HAVE PAPERS IN LEARNGUARD, WHY AM I HAVING
 * IT HERE NOW").
 *
 * "Paper" was invented here and has no counterpart in the reference
 * repo, where the thing a student sits IS the assessment (a Moodle
 * activity instance). Worse, "Paper" already means something else in
 * SKULOS entirely - ExamPaper is an exam-day timetable row. Renamed
 * throughout to `cbt_assessments`.
 *
 * Also closes the real functional gap that naming hid. In Moodle,
 * LearnGuard inherits its whole context from the COURSE the activity
 * sits in: which subject it belongs to, which term, and - critically -
 * who is allowed to attempt it (course enrolment). SKULOS has no
 * courses, so an assessment here floated free and was silently offered
 * to every student in the school. Per the user's own answer ("ALL
 * THREE OPTIONS ABOVE") an assessment now supports all three shapes at
 * once, with no separate models:
 *
 *   - subject_id + term_id: optional academic context, and where a
 *     score is destined once results integration lands.
 *   - cbt_assessment_class_arms: attach any number of class arms;
 *     eligibility flows from real enrolment exactly as Moodle derives
 *     it from course enrolment. One arm = a single-class assessment,
 *     several = a common/year-group paper.
 *   - cbt_assessment_takers: explicitly assigned individual takers
 *     (the Bulk Assignment screen), added ON TOP of whatever the
 *     attached arms already give. Attach no arms at all and this alone
 *     defines eligibility - the standalone case.
 *
 * Eligibility is therefore always the UNION of the two, never one or
 * the other, so the three shapes compose instead of competing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->rename('cbt_papers', 'cbt_assessments');
        Schema::connection('tenant')->rename('cbt_paper_questions', 'cbt_assessment_questions');

        // Every FK pointing at the old name follows it, so no column is
        // left calling an assessment a "paper".
        Schema::connection('tenant')->table('cbt_assessment_questions', function (Blueprint $table) {
            $table->renameColumn('paper_id', 'assessment_id');
        });
        Schema::connection('tenant')->table('cbt_sessions', function (Blueprint $table) {
            $table->renameColumn('paper_id', 'assessment_id');
        });
        Schema::connection('tenant')->table('cbt_precheck_sessions', function (Blueprint $table) {
            $table->renameColumn('paper_id', 'assessment_id');
        });

        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->after('title')->constrained('subjects')->nullOnDelete();
            $table->foreignId('term_id')->nullable()->after('subject_id')->constrained('terms')->nullOnDelete();
        });

        Schema::connection('tenant')->create('cbt_assessment_class_arms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('cbt_assessments')->cascadeOnDelete();
            $table->foreignId('class_arm_id')->constrained('class_arms')->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['assessment_id', 'class_arm_id']);
        });

        Schema::connection('tenant')->create('cbt_assessment_takers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('assessment_id')->constrained('cbt_assessments')->cascadeOnDelete();
            // Polymorphic for the same reason cbt_sessions.takeable is:
            // a School\User today, an Applicant (Admissions' own
            // separate identity/guard) later, with no schema fork.
            $table->string('takeable_type');
            $table->unsignedBigInteger('takeable_id');
            $table->unsignedBigInteger('assigned_by_user_id')->nullable();
            $table->timestamps();

            $table->unique(['assessment_id', 'takeable_type', 'takeable_id'], 'cbt_assessment_takers_unique');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->dropIfExists('cbt_assessment_takers');
        Schema::connection('tenant')->dropIfExists('cbt_assessment_class_arms');

        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_id');
            $table->dropConstrainedForeignId('term_id');
        });

        Schema::connection('tenant')->table('cbt_precheck_sessions', function (Blueprint $table) {
            $table->renameColumn('assessment_id', 'paper_id');
        });
        Schema::connection('tenant')->table('cbt_sessions', function (Blueprint $table) {
            $table->renameColumn('assessment_id', 'paper_id');
        });
        Schema::connection('tenant')->table('cbt_assessment_questions', function (Blueprint $table) {
            $table->renameColumn('assessment_id', 'paper_id');
        });

        Schema::connection('tenant')->rename('cbt_assessment_questions', 'cbt_paper_questions');
        Schema::connection('tenant')->rename('cbt_assessments', 'cbt_papers');
    }
};
