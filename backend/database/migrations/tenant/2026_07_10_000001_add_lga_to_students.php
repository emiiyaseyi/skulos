<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Local Government Area (user request 2026-07-10): a distinct field from
 * state_of_origin, both are standard on Nigerian student records. Optional,
 * like the rest of the extended profile fields it sits alongside.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->string('lga')->nullable()->after('state_of_origin');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn('lga');
        });
    }
};
