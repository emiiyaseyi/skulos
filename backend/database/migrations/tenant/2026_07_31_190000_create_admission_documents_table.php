<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions module, Phase 1f (user directive 2026-07-31: "ought to have
 * other steps... filling candidate birth certificate, last report card
 * if available etc, all these are optional by default"). Same approval
 * state machine shape as AdmissionPayment (itself copied from Payment):
 * status/verified_by_user_id/verified_at/notes deliberately NOT
 * fillable, mutated only via the controller's own atomic update(). One
 * applicant can upload at most one file per document_type — re-uploading
 * replaces the pending/rejected one, same as re-submitting fee evidence.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_documents', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->string('document_type');
            $table->string('file_path');
            $table->string('status')->default('pending');
            $table->unsignedBigInteger('verified_by_user_id')->nullable();
            $table->timestamp('verified_at')->nullable();
            $table->string('notes', 500)->nullable();
            $table->timestamps();

            $table->unique(['applicant_id', 'document_type']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_documents');
    }
};
