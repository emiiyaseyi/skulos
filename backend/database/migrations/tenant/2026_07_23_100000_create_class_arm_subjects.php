<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per stream subject offerings (user report 2026-07-23, live on greenfield:
 * "in SSS, all subjects are not done by all students as we have 3 streams
 * per class... only mandatory subjects are Maths, English and Civic...
 * after the mandatory subjects, others are not mandatory"). class_subject
 * already exists for "this class offers these subjects" at the whole
 * SchoolClass level, correct for every class that has only one stream
 * (Nursery through JSS in practice), but an SSS class's Arts/Science/
 * Commercial streams each need their OWN distinct subject list, not one
 * shared list every student in the class sees regardless of stream.
 *
 * Deliberately additive, not a migration of class_subject itself: an
 * empty class_arm_subjects for a given arm means "no override, use the
 * class's own subject list", the exact behaviour every class already has
 * today, so nothing changes for a school that never touches this. Only a
 * class arm with explicit rows here (SSS's 3 streams) gets its own
 * narrower list. See ClassArm::offeredSubjects() for the resolver.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_arm_subjects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_arm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_mandatory')->default(true);
            $table->boolean('is_examinable')->default(true);
            $table->timestamps();
            $table->unique(['class_arm_id', 'subject_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('class_arm_subjects');
    }
};
