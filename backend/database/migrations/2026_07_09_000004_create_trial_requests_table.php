<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Public "Start a Trial" leads from the marketing site (user request
 * 2026-07-09): a brief form, platform admin reviews and approves or
 * rejects, the applicant is emailed either way. Platform database
 * (these exist before any school/tenant database does).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('trial_requests', function (Blueprint $table) {
            $table->id();
            $table->string('school_name');
            $table->string('contact_name');
            $table->string('email');
            $table->string('phone')->nullable();
            $table->unsignedInteger('student_count')->nullable();
            $table->text('message')->nullable();
            $table->string('status')->default('pending'); // pending | approved | rejected
            $table->text('review_note')->nullable();
            $table->foreignId('reviewed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();
            $table->index('status');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('trial_requests');
    }
};
