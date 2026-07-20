<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Confirm & Renew (user request 2026-07-19: a school's amount due
 * changed mid cycle and was paid across two separate verified payments;
 * "there should be a way for the platform owner to confirm and renew the
 * school plan"). A verified payment is "applied" the moment an admin
 * uses it toward a renewal, so the next cycle's "amount received so far"
 * total never double counts a payment that already renewed an earlier
 * cycle.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->timestamp('applied_to_renewal_at')->nullable()->after('verified_at');
        });
    }

    public function down(): void
    {
        Schema::table('subscription_payments', function (Blueprint $table) {
            $table->dropColumn('applied_to_renewal_at');
        });
    }
};
