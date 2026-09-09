<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school with more than one logo (e.g. one for Nursery & Primary, a
 * different one for Secondary, see school_section_brandings) can choose
 * whether its login page shows all of them together or just the one
 * school-wide default (user directive 2026-09-09: "add the option for
 * school to decide if they want both logo on the login page or one
 * default logo"). Defaults false, so every existing school's login page
 * is unchanged until it opts in.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->boolean('show_all_section_logos_on_login')->default(false)->after('login_box_side');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('show_all_section_logos_on_login');
        });
    }
};
