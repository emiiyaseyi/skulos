<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A real term switcher for the regular weekly class timetable (user
 * request 2026-07-28: "Timetable pages should also have term switcher
 * too"). `timetable_entries` previously had no term concept at all, one
 * ongoing weekly grid per class arm regardless of term — that was fine
 * for a school whose timetable genuinely never changes, but gave no way
 * for a school that DOES restructure who teaches what mid year to keep
 * more than one term's grid around at once.
 *
 * `term_id` is nullable and deliberately NOT backfilled onto existing
 * rows: a NULL term_id is the "applies to every term" universal
 * fallback (TimetableService merges it with any term-specific override
 * per cell, term-specific wins), so every already-provisioned school's
 * existing timetable keeps working exactly as before with zero data
 * migration, until an admin explicitly saves a term-specific override
 * for a particular term.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->dropUnique('timetable_entry_class_slot_unique');
            // nullOnDelete, not cascade: a deleted term should fall the
            // slot back to the universal entry, never silently destroy
            // real class/subject/staff assignment data along with it.
            $table->foreignId('term_id')->nullable()->after('class_arm_id')->constrained()->nullOnDelete();
            $table->unique(['class_arm_id', 'day_of_week', 'timetable_period_id', 'term_id'], 'timetable_entry_class_slot_term_unique');
        });
    }

    public function down(): void
    {
        Schema::table('timetable_entries', function (Blueprint $table) {
            $table->dropUnique('timetable_entry_class_slot_term_unique');
            $table->dropConstrainedForeignId('term_id');
            $table->unique(['class_arm_id', 'day_of_week', 'timetable_period_id'], 'timetable_entry_class_slot_unique');
        });
    }
};
