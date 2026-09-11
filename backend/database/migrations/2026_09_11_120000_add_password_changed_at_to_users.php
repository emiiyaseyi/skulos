<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a staff/student/guardian profile show WHEN a login's password was
 * last actually changed by the account holder themselves (user request
 * 2026-09-11: "the default password if the teacher is yet to change it
 * but if it has been changed, put password last change date and time"),
 * rather than only the boolean must_change_password. Null means still on
 * whatever password was last set for them (the original default, or the
 * one an admin's "Reset password" action just issued); set the moment
 * they change it themselves via ProfileController::updatePassword(), and
 * cleared back to null by AccountProvisioningService::resetPassword() so
 * a reset correctly reads as "default password" again, not a stale date.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->timestamp('password_changed_at')->nullable()->after('must_change_password');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('password_changed_at');
        });
    }
};
