<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * One class teacher entered remark per student, per term, per skill area
 * (see report_card_skill_areas) -- the 'skills' narrative style's own
 * analogue of student_subject_comments, kept as a genuinely separate
 * table rather than repurposing subject comments so a skill area never
 * has to masquerade as a real Subject (which would leak into curriculum/
 * teaching-assignment screens that have nothing to do with this).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('student_skill_remarks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('student_id');
            $table->unsignedBigInteger('term_id');
            $table->unsignedBigInteger('skill_area_id');
            $table->text('remark')->nullable();
            $table->timestamps();
            $table->unique(['student_id', 'term_id', 'skill_area_id'], 'student_skill_remarks_unique');
            $table->index(['term_id', 'skill_area_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('student_skill_remarks');
    }
};
