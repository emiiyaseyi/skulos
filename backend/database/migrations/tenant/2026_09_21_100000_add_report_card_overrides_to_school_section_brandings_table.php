<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-section report card look override (user directive 2026-09-21: a
 * school where every section has its own distinct report card design
 * needs this supported as a platform capability, not hand built once for
 * one school). Same "own override replaces the fallback" shape
 * SchoolSectionBranding already established for logo/brand_color: a
 * null column here means the section simply inherits the school wide
 * ReportCardPreferences choice (see ReportCardPreferences::templateFor()),
 * so every already-provisioned school is completely unaffected until an
 * admin deliberately sets one of these for a section.
 *
 * `narrative_style` only has any visible effect on a class that is
 * ALREADY in Narrative Evaluation mode for the term being rendered
 * (ClassNarrativeTerm) — it chooses between the existing free-text
 * per-subject comments table ('comments', the default, byte-for-byte
 * the pre-existing behaviour) and a new grouped category/skill remark
 * table ('skills', built for a Nursery/Early Years style rubric).
 *
 * `watermark_enabled` is a plain boolean, not nullable/tri-state like
 * the others: a watermark is a pure on/off visual choice with no
 * meaningful "inherit the school's choice" concept the way a template
 * pick has, so it defaults directly to false (off) for every section.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_section_brandings', function (Blueprint $table) {
            $table->string('report_card_template')->nullable()->after('brand_color');
            $table->string('narrative_style')->nullable()->after('report_card_template');
            $table->boolean('watermark_enabled')->default(false)->after('narrative_style');
        });
    }

    public function down(): void
    {
        Schema::table('school_section_brandings', function (Blueprint $table) {
            $table->dropColumn(['report_card_template', 'narrative_style', 'watermark_enabled']);
        });
    }
};
