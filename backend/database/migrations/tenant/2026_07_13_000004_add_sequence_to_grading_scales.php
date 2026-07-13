<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The Grading page listed scales alphabetically by name (found live
 * 2026-07-13: "Junior Secondary Scale" sorted before "Nursery and
 * Primary Scale"), the same class of bug already fixed for Section/
 * SchoolClass/Subject ordering the same week. A scale now has an
 * explicit pedagogical position instead.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('grading_scales', function (Blueprint $table) {
            $table->unsignedInteger('sequence')->default(0)->after('is_default');
        });
    }

    public function down(): void
    {
        Schema::table('grading_scales', function (Blueprint $table) {
            $table->dropColumn('sequence');
        });
    }
};
