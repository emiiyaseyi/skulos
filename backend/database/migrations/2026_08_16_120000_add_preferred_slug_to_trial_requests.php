<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a school choose its own subdomain when requesting a trial
 * (2026-08-16), rather than having one derived from its name and only
 * discovering it in the approval email. Nullable: a school that skips
 * the field still gets a generated one, and an approving admin can
 * override either way.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trial_requests', function (Blueprint $table) {
            $table->string('preferred_slug', 60)->nullable()->after('school_name');
        });
    }

    public function down(): void
    {
        Schema::table('trial_requests', function (Blueprint $table) {
            $table->dropColumn('preferred_slug');
        });
    }
};
