<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated offer -> acceptance pipeline (user directive 2026-08-06): a
 * Student row created at acceptance time starts life "pending_enrollment"
 * (kept off rosters/attendance/gradebooks, see Student::STATUS_
 * PENDING_ENROLLMENT) until fees clear and the receiving class teacher
 * onboards them. admission_fees_cleared_at is set once both optional and
 * non-optional new-admission fees are paid (FeeBalanceService::
 * newAdmissionFeesCleared()); onboarded_at/onboarded_by_user_id record
 * the class teacher's own confirm action (ClassTeacherOnboardingService).
 * onboarded_by_user_id is a plain int, no cross-database FK, same
 * convention Notification::user_id already uses for a platform user id
 * referenced from a tenant table.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->timestamp('admission_fees_cleared_at')->nullable()->after('status');
            $table->timestamp('onboarded_at')->nullable()->after('admission_fees_cleared_at');
            $table->unsignedBigInteger('onboarded_by_user_id')->nullable()->after('onboarded_at');
        });
    }

    public function down(): void
    {
        Schema::table('students', function (Blueprint $table) {
            $table->dropColumn(['admission_fees_cleared_at', 'onboarded_at', 'onboarded_by_user_id']);
        });
    }
};
