<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school's next billing date (user request 2026-07-10: show it on the
 * Schools list). Admin set for now, not automatically computed, since
 * there is no invoicing/payment cycle engine yet (tracker section 14,
 * Professional tier Finance features are not built). Nullable, shown as
 * "Not set" until the admin enters one or the future Billing/invoicing
 * engine starts maintaining it automatically.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->date('next_billing_date')->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('next_billing_date');
        });
    }
};
