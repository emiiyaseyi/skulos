<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-school custom pricing (2026-08-07, user directive: "change a school
 * plan amount if its needs to increase or decrease the cost per child per
 * term"). Null (every existing school, unchanged) means "use the assigned
 * Plan's price" — the only lever before this was reassigning the whole
 * Plan, which also changes the feature/module set, not just the price.
 * See SubscriptionService::effectivePrice() for the one place this is
 * actually resolved.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->decimal('price_per_student_per_term_override', 10, 2)->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('price_per_student_per_term_override');
        });
    }
};
