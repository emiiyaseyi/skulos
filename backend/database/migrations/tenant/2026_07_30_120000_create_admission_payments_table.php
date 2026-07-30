<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions module, Phase 1d. A separate table from the Finance
 * module's own `payments` (not a nullable applicant_id bolted onto it):
 * that table is tightly coupled to Student/FeeItem/installment plans,
 * none of which an applicant has yet, and making its own required
 * student_id nullable to fit this in would be real regression risk on an
 * already-audited, working table for no real benefit. Same shape copied
 * deliberately though: App\Models\Tenant\Payment's approval state
 * machine (status/verified_by_user_id/verified_at/notes, all excluded
 * from $fillable, mutated only via the controller's atomic update()).
 *
 * fee_type distinguishes the application fee (paid at registration) from
 * the acceptance fee (paid once an offer is made, Phase 1g) — the same
 * evidence-upload-then-verify flow, different point in the pipeline.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_payments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->string('fee_type')->default('application');
            $table->decimal('amount', 12, 2);
            $table->string('bank_name')->nullable();
            $table->string('reference')->nullable();
            $table->string('evidence_path')->nullable();
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_payments');
    }
};
