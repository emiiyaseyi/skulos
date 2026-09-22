<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Beginning/End of Term physical stats tracking, made per-section/class
 * (user directive 2026-09-22, confirmed via AskUserQuestion: "Make it
 * per-section" -- turning the pre-existing school-wide toggle on for
 * PPMS's Nursery section would have also switched it on for Primary and
 * Secondary, which never asked for it). Nullable tri-state, the same
 * shape watermark_enabled already uses on both these tables: null
 * inherits (class falls to section, section falls to the school wide
 * Setting -- untouched, still the ultimate fallback for a school that
 * never touches this at all).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->boolean('tracks_physical_stats_start')->nullable()->after('layout_override');
        });

        Schema::table('school_section_brandings', function (Blueprint $table) {
            $table->boolean('tracks_physical_stats_start')->nullable()->after('layout_override');
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropColumn('tracks_physical_stats_start');
        });

        Schema::table('school_section_brandings', function (Blueprint $table) {
            $table->dropColumn('tracks_physical_stats_start');
        });
    }
};
