<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform subscription billing (user request 2026-07-17): the reverse
 * direction of the Foundation Finance slice, a school pays SKULOS
 * itself, verified the same way (bank transfer + evidence + human
 * verification), instead of a parent paying the school. Platform
 * scoped (not tenant), since School and User both live here already,
 * so this can use real foreign keys, unlike the tenant-side Payment
 * table's necessarily FK-less user references.
 *
 * suspension_reason distinguishes a school locked out for a genuinely
 * overdue subscription (School::SUSPENSION_REASON_BILLING, driven by
 * this feature's own "Demand payment" action) from the pre-existing,
 * unrelated manual "Suspend" action a platform admin could already take
 * for any other reason, so ResolveTenant's 403 message can actually
 * tell a locked-out school why and what to do about it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('suspension_reason')->nullable()->after('status');
        });

        Schema::create('subscription_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->decimal('amount', 12, 2);
            $table->string('bank_name')->nullable();
            $table->string('reference')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('status')->default('pending');
            $table->foreignId('submitted_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->foreignId('verified_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('verified_at')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'status']);
        });

        // One row per reminder/extension/demand action a platform admin
        // takes on a school's subscription, so the school-facing screen
        // can show "last reminded 3 days ago" and the platform admin has
        // a real history, not just the current next_billing_date value.
        Schema::create('subscription_events', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('type'); // reminder | extension | demand | reinstated
            $table->text('note')->nullable();
            $table->date('extended_to')->nullable(); // only set for type=extension
            $table->foreignId('actioned_by_user_id')->constrained('users')->cascadeOnDelete();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('subscription_events');
        Schema::dropIfExists('subscription_payments');
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('suspension_reason');
        });
    }
};
