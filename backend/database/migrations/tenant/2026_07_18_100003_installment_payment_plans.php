<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Installment Payment Plans (Advance Finance, 2026-07-18): a fee item can
 * optionally be split into a scheduled series of partial payments instead
 * of one lump submission, the natural extension of the Foundation Finance
 * slice's "Bank Transfer + Evidence + Verification" flow flagged as
 * deferred when that slice shipped (2026-07-17). A Payment can now
 * optionally reference which installment it was submitted against,
 * exactly the same optional-fee_item_id shape Payment already has for
 * "which fee item", so a school that never turns this on keeps behaving
 * exactly as before.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('fee_items', function (Blueprint $table) {
            $table->boolean('allow_installments')->default(false)->after('is_active');
        });

        Schema::create('fee_item_installments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('fee_item_id')->constrained()->cascadeOnDelete();
            $table->string('label'); // e.g. "1st Installment"
            $table->decimal('amount', 10, 2);
            $table->date('due_date')->nullable();
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->timestamps();
        });

        Schema::table('payments', function (Blueprint $table) {
            $table->foreignId('fee_item_installment_id')->nullable()->after('fee_item_id')
                ->constrained('fee_item_installments')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropConstrainedForeignId('fee_item_installment_id');
        });
        Schema::dropIfExists('fee_item_installments');
        Schema::table('fee_items', function (Blueprint $table) {
            $table->dropColumn('allow_installments');
        });
    }
};
