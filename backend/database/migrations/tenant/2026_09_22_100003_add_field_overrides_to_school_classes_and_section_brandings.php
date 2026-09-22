<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Report card field visibility (Physical & Attendance, Stamp/Signature,
 * etc.), made per-section/class instead of one school wide switch (user
 * directive 2026-09-22, confirmed via AskUserQuestion: "Make these
 * per-section too" -- so turning Physical & Attendance on for Nursery
 * never also turns it on for Primary/Secondary). A generic {field_code:
 * bool} json map, the same "own override replaces the fallback for
 * that one field" shape already established on these two tables, but
 * covering any field code rather than one dedicated column per field --
 * only physical_stats/stamp_signature are wired to real UI today, the
 * column itself is not limited to those two.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->json('field_overrides')->nullable()->after('tracks_physical_stats_start');
        });

        Schema::table('school_section_brandings', function (Blueprint $table) {
            $table->json('field_overrides')->nullable()->after('watermark_text');
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropColumn('field_overrides');
        });

        Schema::table('school_section_brandings', function (Blueprint $table) {
            $table->dropColumn('field_overrides');
        });
    }
};
