<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A section's own grading scale (user request 2026-07-24: "add a Grade
 * to this section which is based on the overall average gotten by the
 * student... check the section grade structure to get the grade to be
 * placed here... it changes based on the section and grade set").
 * Grading scales already existed named per section by convention
 * ("Nursery and Primary Scale", "Junior Secondary Scale", "Senior
 * Secondary Scale (WAEC)") but nothing actually linked a Section to
 * which one applies to it, every report card always used whichever
 * scale was marked the single school wide default regardless of the
 * student's own section. Nullable: a section with none assigned falls
 * back to the default scale, exactly today's behaviour, unaffected
 * until an admin explicitly assigns one from the Grading settings page.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->foreignId('grading_scale_id')->nullable()->after('sequence')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropConstrainedForeignId('grading_scale_id');
        });
    }
};
