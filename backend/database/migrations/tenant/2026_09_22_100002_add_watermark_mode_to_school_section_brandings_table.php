<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The report card watermark, beyond a plain on/off of the section's own
 * logo (user directive 2026-09-22: "let the water mark use the school
 * section logo as the default watermark or make it possible for the
 * school to upload another or add a text"). Three modes, watermark_mode
 * null/'logo' (the default, every existing section's current behaviour
 * unchanged) uses the section's own resolved logo exactly as before;
 * 'custom' uses a separately uploaded image; 'text' renders a plain
 * text watermark instead of an image. Section level only, matching
 * where logo_path/brand_color already live -- watermark_enabled itself
 * stays the existing on/off, at both section and class level.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_section_brandings', function (Blueprint $table) {
            $table->string('watermark_mode')->nullable()->after('tracks_physical_stats_start');
            $table->string('watermark_path')->nullable()->after('watermark_mode');
            $table->string('watermark_text')->nullable()->after('watermark_path');
        });
    }

    public function down(): void
    {
        Schema::table('school_section_brandings', function (Blueprint $table) {
            $table->dropColumn(['watermark_mode', 'watermark_path', 'watermark_text']);
        });
    }
};
