<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The academic spine of a school (tracker §5, TD-007): sessions, terms,
 * sections, classes, arms, the enrollable class-arm unit, subjects, and
 * houses. Everything here is school-configurable — names, counts, and
 * sequences are data, never hardcoded. Lives in each school's own
 * (tenant) database.
 */
return new class extends Migration
{
    public function up(): void
    {
        // e.g. "2025/2026"
        Schema::create('academic_sessions', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->unique('name');
        });

        // e.g. "First Term" — sequence lets a school run 2, 3, or N terms.
        Schema::create('terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('academic_session_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->date('starts_on')->nullable();
            $table->date('ends_on')->nullable();
            $table->boolean('is_current')->default(false);
            $table->timestamps();
            $table->unique(['academic_session_id', 'sequence']);
        });

        // e.g. "Nursery", "Primary", "Secondary" — renameable, extensible.
        Schema::create('sections', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->timestamps();
            $table->unique('name');
        });

        // e.g. "Basic 1", "JSS 1"
        Schema::create('school_classes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('section_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->unsignedSmallInteger('sequence')->default(1);
            $table->timestamps();
            $table->unique(['section_id', 'name']);
        });

        // School-wide list of arm labels: "A", "B", "Gold", "Silver".
        Schema::create('arms', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->unique('name');
        });

        // The enrollable unit: "Basic 1 A". arm_id null = single-stream class.
        // class_teacher_staff_id is set once the staff table exists.
        Schema::create('class_arms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('arm_id')->nullable()->constrained()->nullOnDelete();
            $table->unsignedBigInteger('class_teacher_staff_id')->nullable();
            $table->unsignedInteger('capacity')->nullable();
            $table->timestamps();
            $table->unique(['school_class_id', 'arm_id']);
            $table->index('class_teacher_staff_id');
        });

        // e.g. "Mathematics"
        Schema::create('subjects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('code')->nullable();
            $table->timestamps();
            $table->unique('name');
        });

        // Which subjects each class offers (arranged by section upstream).
        Schema::create('class_subject', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('subject_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['school_class_id', 'subject_id']);
        });

        // e.g. "Red House" + housemaster (staff) + members (students).
        Schema::create('houses', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->string('color')->nullable();
            $table->unsignedBigInteger('housemaster_staff_id')->nullable();
            $table->timestamps();
            $table->unique('name');
            $table->index('housemaster_staff_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('houses');
        Schema::dropIfExists('class_subject');
        Schema::dropIfExists('subjects');
        Schema::dropIfExists('class_arms');
        Schema::dropIfExists('arms');
        Schema::dropIfExists('school_classes');
        Schema::dropIfExists('sections');
        Schema::dropIfExists('terms');
        Schema::dropIfExists('academic_sessions');
    }
};
