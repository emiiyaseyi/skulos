<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two real gaps found matching a school's own reference report card
 * (user directive 2026-09-21):
 *
 * `overall_remark`: the reference has "OVERALL remarks" as its own
 * distinct line, separate from "CLASS TEACHER'S COMMENT" and "HEAD
 * TEACHER'S COMMENT" -- this app only ever had the latter two.
 *
 * `height_start`/`weight_start`: the reference splits weight/height
 * into "Beginning of Term" and "End of Term" columns. The existing
 * `height`/`weight` columns become the END-of-term value (byte for
 * byte what they already meant, so every already-entered value keeps
 * meaning exactly what it always did); these two new nullable columns
 * hold the BEGINNING-of-term value, only ever shown/collected when a
 * school opts into that mode (confirmed via AskUserQuestion: "add it
 * to what admin can enable if they want the stat to have beginning of
 * term and end of term or just one to cover it" -- see the new
 * `physical_stats_track_start` Setting, App\Services\ReportCards\
 * ReportCardPreferences).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('student_term_remarks', function (Blueprint $table) {
            $table->text('overall_remark')->nullable()->after('principal_remark');
            $table->string('height_start')->nullable()->after('height');
            $table->string('weight_start')->nullable()->after('weight');
        });
    }

    public function down(): void
    {
        Schema::table('student_term_remarks', function (Blueprint $table) {
            $table->dropColumn(['overall_remark', 'height_start', 'weight_start']);
        });
    }
};
