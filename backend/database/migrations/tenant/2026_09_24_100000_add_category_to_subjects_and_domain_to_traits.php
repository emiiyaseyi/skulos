<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Two optional labels the grouped "Cognitive Domain" and the Psychomotor /
 * Affective report card sections read (user directive 2026-09-24, from the
 * school's own Basic 1 to 5 report card): a subject can belong to a group
 * ("Languages", "Maths & Sciences", "Arts & Culture"), and a behavioural
 * trait to a domain ("psychomotor" or "affective"). Both nullable, so a
 * school that never sets them sees nothing change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->string('category', 80)->nullable()->after('code');
        });
        Schema::table('behavioural_traits', function (Blueprint $table) {
            $table->string('domain', 20)->nullable()->after('type');
        });
    }

    public function down(): void
    {
        Schema::table('subjects', function (Blueprint $table) {
            $table->dropColumn('category');
        });
        Schema::table('behavioural_traits', function (Blueprint $table) {
            $table->dropColumn('domain');
        });
    }
};
