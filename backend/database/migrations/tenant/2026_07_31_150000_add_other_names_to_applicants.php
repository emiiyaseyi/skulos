<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions module (live feedback 2026-07-31: "field for other name is
 * missing but should be optional"). Same column name/meaning as
 * Student::other_names and Staff::other_names — an optional middle
 * name(s) field, never required.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('other_names')->nullable()->after('first_name');
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropColumn('other_names');
        });
    }
};
