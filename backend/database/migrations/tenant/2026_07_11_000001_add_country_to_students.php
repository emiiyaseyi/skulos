<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Country (user request 2026-07-11: the new reusable country to state to
 * LGA picker's first field, "for uniformity"). Not previously captured for
 * students at all. Plain string like state_of_origin/lga sitting next to
 * it, not a foreign key: a school's tenant database is a separate
 * database/connection from the platform database the countries reference
 * table lives in, so a cross database foreign key isn't possible.
 * Optional, like the rest of the extended profile fields.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('country')->nullable()->after('nationality');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
