<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Demo account" support (2026-08-15, user request: greenfield used as a
 * live demo school for prospective customers to click through, with one
 * real account per major role -- admin, class teacher, subject teacher,
 * parent, student -- that must never be able to change anything,
 * including its own password, even on first login). Scoped to the
 * school_user pivot row (one school membership), not the User itself,
 * per the explicit "this is only for this school" instruction -- the
 * same person could be a genuine, fully-capable user at a different
 * school without this restriction following them there.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_user', function (Blueprint $table) {
            $table->boolean('is_demo_readonly')->default(false)->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('school_user', function (Blueprint $table) {
            $table->dropColumn('is_demo_readonly');
        });
    }
};
