<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Automated offer -> acceptance pipeline (user directive 2026-08-06): a
 * parent explicitly accepts or declines an offer of admission before
 * anything is created, a separate state machine from decision_status
 * (the SCHOOL's own decision) the same way account_status and
 * decision_status already stay deliberately separate on this table (see
 * the decision-fields migration's own docblock). preferred_class_arm_id
 * is the admin's optional stream override, set at offer time when a
 * class has more than one arm; left null, acceptance falls back to the
 * lowest-population arm (ClassArmAssignmentService).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->string('offer_response')->nullable()->after('decision_notes');
            $table->timestamp('offer_responded_at')->nullable()->after('offer_response');
            $table->foreignId('preferred_class_arm_id')->nullable()->after('offer_responded_at')->constrained('class_arms')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('applicants', function (Blueprint $table) {
            $table->dropConstrainedForeignId('preferred_class_arm_id');
            $table->dropColumn(['offer_response', 'offer_responded_at']);
        });
    }
};
