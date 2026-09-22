<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A genuinely different report card block arrangement per class/section
 * (user directive 2026-09-21, confirmed via AskUserQuestion: "A genuinely
 * different arrangement per class/section"). Nullable json on both tables,
 * same "own override replaces the fallback entirely" shape already
 * established for report_card_template/narrative_style/watermark_enabled
 * on these same two tables -- a class's own override wins, else its
 * section's, else the school wide layout (the pre-existing flat Setting
 * rows, untouched). Every already-provisioned school stays exactly as it
 * is today until a class or section deliberately gets its own override.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->json('layout_override')->nullable()->after('watermark_enabled');
        });

        Schema::table('school_section_brandings', function (Blueprint $table) {
            $table->json('layout_override')->nullable()->after('watermark_enabled');
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropColumn('layout_override');
        });

        Schema::table('school_section_brandings', function (Blueprint $table) {
            $table->dropColumn('layout_override');
        });
    }
};
