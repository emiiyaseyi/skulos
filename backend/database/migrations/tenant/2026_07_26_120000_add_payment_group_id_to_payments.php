<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User request 2026-07-26: "for parents with more than one child, add the
 * option of check box to select in case the parent want the payment to
 * cover more than one child." A single evidence upload now creates one
 * Payment row per selected student (each with its own share of the total
 * amount, so verification/records stay per-student as before), tagged with
 * a shared payment_group_id so the records screen can show they came from
 * one submission. Null for an ordinary single-child payment.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->string('payment_group_id', 36)->nullable()->after('evidence_path')->index();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('payment_group_id');
        });
    }
};
