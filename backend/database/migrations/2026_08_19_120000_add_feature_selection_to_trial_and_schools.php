<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Lets a school get a genuinely custom set of platform features instead
 * of only whatever its assigned Plan happens to include (2026-08-19,
 * direct instruction: a trial requester picks the features they want
 * from the full catalog, "the admin can add or remove from the
 * features" both at approval and afterwards).
 *
 * schools.feature_overrides is nullable on purpose, same "own override
 * replaces the plan fallback" shape as price_per_student_per_term_override
 * already established: null (every existing school, unchanged) means
 * "no override, use the plan's own features exactly as before" -- this
 * migration changes nothing about what any already-provisioned school
 * can see. A school gets a real, distinct value here only once an admin
 * explicitly sets one, at trial approval or later from the school's own
 * admin screen.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trial_requests', function (Blueprint $table) {
            // What the prospect asked for on the public form. Kept even
            // after approval as a record of the original request,
            // separate from the school's own (admin-editable) actual
            // grant in schools.feature_overrides.
            $table->json('requested_features')->nullable()->after('message');
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->json('feature_overrides')->nullable()->after('plan_id');
        });
    }

    public function down(): void
    {
        Schema::table('trial_requests', function (Blueprint $table) {
            $table->dropColumn('requested_features');
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('feature_overrides');
        });
    }
};
