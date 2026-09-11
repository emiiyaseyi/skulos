<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Multi-role accounts (user directive 2026-09-11: "we have some users
 * that they are both teacher and parent, add a feature to the admin
 * setting that allows one account to serve as both parent and teacher...
 * allow the school admin to also be a class teacher or subject teacher,
 * or other roles"). A user's real, structural role at a school stays
 * exactly what it always was -- school_user.role_id, one row per
 * (school_id, user_id), untouched here. This table is purely additive:
 * a role a user ALSO holds, beyond their primary one, switchable via a
 * session value (see User::roleIn()) rather than a second membership row.
 *
 * Platform database, not tenant -- roles/school_user/permissions/
 * user_permissions all live here already (see
 * 2026_07_08_000002/000003_*), and this table joins directly against
 * them. Shape mirrors user_permissions (school_id/user_id/foreign key,
 * unique triple) -- the closest existing precedent for "a per-user,
 * per-school grant layered on top of the role system".
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('user_additional_roles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('role_id')->constrained()->cascadeOnDelete();
            // Null when the row was auto-created by provisionAuto()'s own
            // overwrite-avoidance fix (a system action, not an admin
            // clicking Grant) -- see AccountProvisioningService.
            $table->foreignId('granted_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('granted_at')->nullable();
            $table->timestamps();
            $table->unique(['school_id', 'user_id', 'role_id']);
            $table->index(['school_id', 'user_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('user_additional_roles');
    }
};
