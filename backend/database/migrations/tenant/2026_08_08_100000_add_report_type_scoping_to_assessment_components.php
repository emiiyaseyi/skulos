<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mid-term report cards (user request 2026-08-07: "some schools like to
 * have mid term report cards too... admin set what can be used as mid
 * term report and full term report"). Both booleans default to preserve
 * current behavior exactly: every existing component already counts
 * toward what full-term reporting has always meant, nothing changes for
 * a school until an admin explicitly opts a component into mid-term.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            $table->boolean('counts_for_midterm')->default(false)->after('type');
            $table->boolean('counts_for_fullterm')->default(true)->after('counts_for_midterm');
        });
    }

    public function down(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            $table->dropColumn(['counts_for_midterm', 'counts_for_fullterm']);
        });
    }
};
