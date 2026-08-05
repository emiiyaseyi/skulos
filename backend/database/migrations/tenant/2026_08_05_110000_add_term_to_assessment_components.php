<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User directive 2026-08-05: "the assessment structure can be changed
 * per term so its not a fixed one across the whole session or school
 * life time." Same universal+override shape already proven for
 * timetable_entries.term_id (2026-07-28) and timetable_periods.
 * section_id: NULL is a UNIVERSAL component applying to every term
 * (every already-provisioned school's structure, untouched by this
 * migration, keeps working identically); a term that has its own rows
 * uses ONLY those (a real fork, not a merge with the universal set —
 * a term genuinely restructuring CA/Exam weighting should not also
 * inherit whatever the universal set happens to contain).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            $table->foreignId('term_id')->nullable()->after('id')->constrained('terms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            $table->dropConstrainedForeignId('term_id');
        });
    }
};
