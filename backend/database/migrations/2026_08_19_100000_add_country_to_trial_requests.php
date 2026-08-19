<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which country a trial-requesting school is in (2026-08-19, direct
 * instruction: "let's add a new question asking for the school
 * location (country)"). Nullable for every already-submitted request,
 * which asked no such question at the time.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trial_requests', function (Blueprint $table) {
            $table->string('country', 100)->nullable()->after('school_name');
        });
    }

    public function down(): void
    {
        Schema::table('trial_requests', function (Blueprint $table) {
            $table->dropColumn('country');
        });
    }
};
