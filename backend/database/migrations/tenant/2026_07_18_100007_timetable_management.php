<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Timetable Management (Advance plan feature, deferred 2026-07-15, then
 * SUSPENDED again briefly during a live nav sweep before the user
 * reversed that and asked to build it 2026-07-18). Two tables: the
 * school's own period/slot catalog (a school configures this once, not
 * hardcoded, seeded from a sane default via `config('skulos.
 * default_timetable_periods')` the same way grading scales/comment banks
 * already are), and the actual weekly grid of what class is taught what
 * subject, by whom, in which slot on which day.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('timetable_periods', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->unsignedSmallInteger('sequence');
            $table->time('starts_at');
            $table->time('ends_at');
            // false for a Break/Lunch row: shown on the grid as a fixed
            // label but never assignable to a subject/teacher.
            $table->boolean('is_teaching_period')->default(true);
            $table->timestamps();
        });

        Schema::create('timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('class_arm_id')->constrained()->cascadeOnDelete();
            $table->foreignId('timetable_period_id')->constrained()->cascadeOnDelete();
            // A key into config('skulos.school_week_days'), e.g. 'monday',
            // not an integer, so the grid columns can be built directly
            // from that catalog with no lookup translation.
            $table->string('day_of_week');
            $table->foreignId('subject_id')->nullable()->constrained()->nullOnDelete();
            // Plain id, no FK, matching class_teacher_staff_id's own
            // established convention (Academic structure build log):
            // this can be set/changed independent of Staff table timing.
            $table->unsignedBigInteger('staff_id')->nullable();
            $table->timestamps();
            $table->unique(['class_arm_id', 'day_of_week', 'timetable_period_id'], 'timetable_entry_class_slot_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('timetable_entries');
        Schema::dropIfExists('timetable_periods');
    }
};
