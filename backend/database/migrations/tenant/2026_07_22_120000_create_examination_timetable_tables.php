<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Examination Timetable (user request 2026-07-21): "there should be a
 * subsection for examination timetable that the school admin will
 * populate either by entering it in the provided fields (Paper 1, Paper
 * 2, Break, Paper 3... the duration per paper and break, when the first
 * paper starts and the time auto populates for other ones) — or the
 * admin can upload a template... this is done by selecting which section
 * of the school is meant for... it can also be specific to just a class".
 *
 * Deliberately its own pair of tables, not a reuse of timetable_periods/
 * timetable_entries: those are a recurring WEEKLY grid (day_of_week), an
 * exam timetable is DATED (specific calendar dates across an exam
 * window) and duration-driven (the admin sets one duration per paper and
 * a single start time, every other paper's start/end is computed by
 * chaining durations, not entered independently per row the way a
 * regular period's start/end already is).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('exam_papers', function (Blueprint $table) {
            $table->id();
            $table->string('name'); // "Paper 1", "Break", "Paper 2", admin's own wording
            $table->unsignedSmallInteger('sequence');
            $table->unsignedSmallInteger('duration_minutes');
            $table->boolean('is_break')->default(false);
            // Computed by chaining durations off the catalog's own first
            // paper's start time, stored so the entry screen/CSV template
            // can show real times without recomputing on every read.
            $table->time('starts_at')->nullable();
            $table->time('ends_at')->nullable();
            $table->timestamps();
        });

        Schema::create('exam_timetable_entries', function (Blueprint $table) {
            $table->id();
            $table->date('exam_date');
            $table->foreignId('exam_paper_id')->constrained('exam_papers')->cascadeOnDelete();
            // 'section' or 'class': a school can scope a paper's subject
            // either to a whole section (every class in it sits the same
            // paper at the same time, the common case) or to one specific
            // class, per the user's own "it can also be specific to just
            // a class and not only a section".
            $table->string('scope_type', 10);
            $table->unsignedBigInteger('scope_id');
            $table->foreignId('subject_id')->nullable()->constrained('subjects')->nullOnDelete();
            $table->timestamps();
            $table->unique(['exam_date', 'exam_paper_id', 'scope_type', 'scope_id'], 'exam_entry_unique_slot');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('exam_timetable_entries');
        Schema::dropIfExists('exam_papers');
    }
};
