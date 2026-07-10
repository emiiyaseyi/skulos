<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Groups each assessment component under "Test / Continuous Assessment" or
 * "Examinations" for the admin screen (user request 2026-07-10). Purely
 * organizational: ResultService still sums every component regardless of
 * type, this does not change how a subject total or grade is computed,
 * a school's CA/Exam split can still be any formula summing to any total.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            $table->string('type')->default('ca')->after('name'); // 'ca' or 'exam'
        });
    }

    public function down(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            $table->dropColumn('type');
        });
    }
};
