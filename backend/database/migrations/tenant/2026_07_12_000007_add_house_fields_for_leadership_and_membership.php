<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * House Master already existed (housemaster_staff_id); adds an Assistant
 * House Master, and lets staff and parents belong to a house the same way
 * a student already does (user directive 2026-07-12: "the house master and
 * its assistant selected from list of teachers... all Members of the
 * house should be grouped into three 1, Students, 2. teachers and 3.
 * parents... Share all staff and parents into the houses under each
 * school").
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('houses', function (Blueprint $table) {
            $table->unsignedBigInteger('assistant_housemaster_staff_id')->nullable()->after('housemaster_staff_id');
            $table->index('assistant_housemaster_staff_id');
        });

        Schema::table('staff', function (Blueprint $table) {
            $table->unsignedBigInteger('house_id')->nullable()->after('status');
            $table->index('house_id');
        });

        Schema::table('student_guardians', function (Blueprint $table) {
            $table->unsignedBigInteger('house_id')->nullable()->after('is_primary');
            $table->index('house_id');
        });
    }

    public function down(): void
    {
        Schema::table('houses', function (Blueprint $table) {
            $table->dropColumn('assistant_housemaster_staff_id');
        });
        Schema::table('staff', function (Blueprint $table) {
            $table->dropColumn('house_id');
        });
        Schema::table('student_guardians', function (Blueprint $table) {
            $table->dropColumn('house_id');
        });
    }
};
