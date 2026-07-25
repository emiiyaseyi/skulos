<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school's own receiving bank account(s) for the Bank Transfer payment
 * option (user request 2026-07-25: "School admin sets the school account
 * options for payment which parents sees, a school can add more than one
 * account details and the parent sees all"). Purely informational, shown
 * to a parent submitting a Payment so they know where to actually send
 * the money before uploading evidence, never itself validated against a
 * Payment (no gateway, no automated matching, matching the rest of the
 * Foundation Finance slice).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('bank_accounts', function (Blueprint $table) {
            $table->id();
            $table->string('bank_name');
            $table->string('account_name');
            $table->string('account_number');
            $table->boolean('is_active')->default(true);
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
            $table->index('is_active');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('bank_accounts');
    }
};
