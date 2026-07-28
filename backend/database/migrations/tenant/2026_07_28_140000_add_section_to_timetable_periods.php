<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Section-specific period durations (user report, real gap found in the
 * 2026-07-28 feedback re-audit: a school's Nursery/Primary periods often
 * run shorter than Secondary's, but TimetablePeriod was one flat catalog
 * shared by every class arm regardless of section). PopulateTimetables.php
 * had already flagged this exact gap in its own docblock as "a materially
 * bigger schema change... out of scope" for that earlier task; this is
 * that change.
 *
 * `section_id` is nullable and deliberately NOT backfilled onto existing
 * rows, the same "NULL is a real universal fallback" shape term_id used
 * on timetable_entries the same day: a NULL row is the shared/default
 * catalog every section falls back to until it has periods of its own. A
 * section with ANY of its own rows (section_id = that section) uses ONLY
 * those, a full independent catalog for that section, not a merge — the
 * same "own override replaces the universal set entirely" semantics
 * already established and used for term_id.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('timetable_periods', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('timetable_periods', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });
    }
};
