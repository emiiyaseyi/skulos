<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Forces a fresh sign-in through a change-password page before reaching
 * anywhere else, whenever the current password was set by someone other
 * than the user themselves (user directive 2026-07-13: "all first time
 * users must change the default password when they login in for the
 * first time"). Set true by AccountProvisioningService::provision()/
 * resetPassword(), and by the school-owner creation flow; cleared the
 * moment the user changes it themselves, whether via the mandatory gate
 * or a voluntary change on their own profile.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->boolean('must_change_password')->default(false)->after('password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('must_change_password');
        });
    }
};
