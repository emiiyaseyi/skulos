<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Storage Dashboard (Dbase recommendation): "visual breakdown of storage
 * used against the school's plan allowance." Nullable, no value guessed
 * or hardcoded per tier — stays unset (no limit shown) until the platform
 * admin actually sets one per plan in Billing.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->unsignedInteger('storage_limit_mb')->nullable()->after('price_per_student_per_term');
        });
    }

    public function down(): void
    {
        Schema::table('plans', function (Blueprint $table) {
            $table->dropColumn('storage_limit_mb');
        });
    }
};
