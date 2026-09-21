<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The same per-section report card override (2026-09-21) one level
 * finer: a school directive extended it to class level, "one can tick
 * the section or classes the report card is meant to be for" -- a
 * section like Nursery might have Creche/Reception AND KG 1/KG 2, and
 * one of those classes may want its own look distinct from the rest of
 * the section.
 *
 * Every column stays nullable and mirrors SchoolSectionBranding's own
 * three columns exactly: null means "inherit the section's own choice"
 * (which itself may inherit the school wide default), so a class that
 * never sets one of these is completely unaffected. See
 * SchoolClass::resolvedNarrativeStyle()/resolvedWatermarkEnabled() and
 * ReportCardPreferences::templateForClass() for the actual fallback
 * chain: class's own value, then its section's, then (for template
 * only) the school wide ReportCardPreferences choice.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->string('report_card_template')->nullable()->after('evaluation_style');
            $table->string('narrative_style')->nullable()->after('report_card_template');
            $table->boolean('watermark_enabled')->nullable()->after('narrative_style');
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropColumn(['report_card_template', 'narrative_style', 'watermark_enabled']);
        });
    }
};
