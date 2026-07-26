<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * An optional approval step for a new fee item (user request 2026-07-25:
 * "admin can set an approval level for school fees which the school
 * owner, principal, vice principal, head teacher, assistant head
 * teacher or any other name from the staff list"). Defaults every fee
 * item to already 'approved' so a school that never turns this on (the
 * "fee_approval_enabled" tenant Setting, off by default) keeps behaving
 * exactly as it always did; only a school that explicitly enables it and
 * configures a chain ever sees a fee item start 'pending'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_items', function (Blueprint $table) {
            $table->string('approval_status')->default('approved')->after('is_active');
            $table->unsignedBigInteger('approved_by_user_id')->nullable()->after('approval_status');
            $table->timestamp('approved_at')->nullable()->after('approved_by_user_id');
            $table->index('approval_status');
        });
    }

    public function down(): void
    {
        Schema::table('fee_items', function (Blueprint $table) {
            $table->dropColumn(['approval_status', 'approved_by_user_id', 'approved_at']);
        });
    }
};
