<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions module, Phase 1b. Which class/session cycles are actually
 * open to new applicants right now, and what the application fee is for
 * each (user directive 2026-07-30: "which admin will set"). A class with
 * no open window for the current session simply does not appear as a
 * choice in the public application wizard (Phase 1c), rather than every
 * class always being applicable.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_windows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('session_id')->constrained('academic_sessions')->cascadeOnDelete();
            $table->foreignId('school_class_id')->constrained('school_classes')->cascadeOnDelete();
            $table->foreignId('campus_id')->nullable()->constrained('campuses')->nullOnDelete();
            $table->timestamp('opens_at')->nullable();
            $table->timestamp('closes_at')->nullable();
            $table->decimal('application_fee_amount', 12, 2)->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['session_id', 'school_class_id', 'campus_id'], 'admission_windows_unique_scope');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_windows');
    }
};
