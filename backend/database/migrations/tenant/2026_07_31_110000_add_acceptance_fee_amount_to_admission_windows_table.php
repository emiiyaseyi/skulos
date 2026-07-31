<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions module, Phase 1g: the acceptance fee (paid once an offer is
 * made, distinct from the application fee already on this table) needs
 * its own admin-set amount — an applicant's uploaded evidence is trusted
 * against a school-configured figure, never a user-submitted one, same
 * reasoning application_fee_amount already exists for.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admission_windows', function (Blueprint $table) {
            $table->decimal('acceptance_fee_amount', 12, 2)->default(0)->after('application_fee_amount');
        });
    }

    public function down(): void
    {
        Schema::table('admission_windows', function (Blueprint $table) {
            $table->dropColumn('acceptance_fee_amount');
        });
    }
};
