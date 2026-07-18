<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report Card enrichment (user request 2026-07-18, from 2 real report
 * card templates supplied as reference: "HEYDAY PRIVATE SCHOOL" nursery
 * evaluation sheets): physical/bio stats, a school-configurable
 * behavioural/psychomotor trait catalog and per-student ratings entered
 * by the class teacher, a per-term "school opened" day count, and a
 * per-subject free-text comment for the comments-only report card mode
 * (as opposed to the existing scored mode).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_term_remarks', function (Blueprint $table) {
            // Physical/bio stats (report card physical info block): a
            // child's height/weight genuinely change term to term, "club"
            // is whichever extracurricular they belong to that term, so
            // all three live on the same per-student-per-term row the
            // remarks already do, entered by the same class teacher at
            // the same time as the remark.
            $table->string('height')->nullable()->after('principal_remark');
            $table->string('weight')->nullable()->after('height');
            $table->string('club')->nullable()->after('weight');
        });

        Schema::table('terms', function (Blueprint $table) {
            // "No. of times school opened" on the report card is a whole
            // -term, whole-school fact (every class shares the same
            // count), not per-student; an admin sets it once per term.
            // "No. in class" and "No. of times absent" are both already
            // computable (a live headcount, and the existing Attendance
            // table), so neither needs a stored column.
            $table->unsignedSmallInteger('total_school_days')->nullable()->after('ends_on');
        });

        // A school-configurable behavioural/psychomotor trait catalog
        // (seeded once from config('skulos.default_behavioural_traits'),
        // then a real tenant table an admin edits from there, the same
        // shape as grading_scales/comments — never read live from config,
        // per the standing "nothing hardcoded" rule). 'type' controls
        // whether the class teacher enters a remark word (Excellent/Very
        // Good/...) or a numeric score for that trait; the admin decides
        // per trait.
        Schema::create('behavioural_traits', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('type')->default('remark'); // remark|score
            $table->unsignedSmallInteger('max_score')->nullable(); // only meaningful when type=score
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        // One rating per student, per term, per trait, entered by the
        // class teacher alongside the remark/physical stats above.
        Schema::create('student_behavioural_ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->foreignId('behavioural_trait_id')->constrained()->cascadeOnDelete();
            $table->string('value')->nullable(); // a remark word, or a numeric score as a string
            $table->timestamps();
            $table->unique(['student_id', 'term_id', 'behavioural_trait_id'], 'student_term_trait_unique');
        });

        // Per-subject free-text comment, the data source for the
        // comments-only report card mode (template 6): unlike the scored
        // templates, that mode shows no C.A./Exam/Total/Grade at all, just
        // a class teacher's own written comment per subject, which no
        // existing table stores (Score is a number, GradingBand's remark
        // is an auto-generated grade-band label, neither is this).
        Schema::create('student_subject_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->text('comment')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'subject_id', 'term_id'], 'student_subject_term_unique');
        });

        // Per-class-arm stamp/signature override (falls back to the
        // school-wide default on School when null): "the school stamp and
        // signature is to be uploaded for all the school[’s] sections and
        // classes but each class teacher can decide to change it".
        Schema::table('class_arms', function (Blueprint $table) {
            $table->string('stamp_path')->nullable()->after('assistant_teacher_staff_id');
            $table->string('signature_path')->nullable()->after('stamp_path');
        });
    }

    public function down(): void
    {
        Schema::table('class_arms', function (Blueprint $table) {
            $table->dropColumn(['stamp_path', 'signature_path']);
        });
        Schema::dropIfExists('student_subject_comments');
        Schema::dropIfExists('student_behavioural_ratings');
        Schema::dropIfExists('behavioural_traits');
        Schema::table('terms', function (Blueprint $table) {
            $table->dropColumn('total_school_days');
        });
        Schema::table('student_term_remarks', function (Blueprint $table) {
            $table->dropColumn(['height', 'weight', 'club']);
        });
    }
};
