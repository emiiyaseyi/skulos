<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Foundation Finance slice (tracker: "a deliberately minimal slice, not
 * the full deferred Finance module from section 5b"): Bank Transfer
 * Payment Option, Payment Evidence Upload, Payment Verification, Basic
 * Fee Records. No payment gateway, no invoicing automation, no payroll,
 * no ledger, none of that is in scope here, see the tracker's Finance
 * catalog for the exact line between this and the full deferred module.
 *
 * `fee_items` is the school's own basic fee catalog per term (e.g.
 * "Tuition", "PTA Levy"), `payments` is one bank transfer submission
 * against one student, evidence_path only ever a storage path/reference
 * (standing rule: never file bytes in the database), verified by a
 * human (Bursar/Accountant/admin), not an automated gateway.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('fee_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->decimal('amount', 10, 2);
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['term_id', 'is_active']);
        });

        Schema::create('payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('student_id')->constrained()->cascadeOnDelete();
            $table->foreignId('fee_item_id')->nullable()->constrained()->nullOnDelete();
            $table->decimal('amount', 10, 2);
            $table->string('method')->default('bank_transfer');
            $table->string('bank_name')->nullable();
            $table->string('reference')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('status')->default('pending');
            // References a platform user, no cross database FK (same
            // pattern as notifications.user_id/chat_messages.sender_user_id).
            $table->unsignedBigInteger('submitted_by_user_id');
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['student_id', 'status']);
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('payments');
        Schema::dropIfExists('fee_items');
    }
};
