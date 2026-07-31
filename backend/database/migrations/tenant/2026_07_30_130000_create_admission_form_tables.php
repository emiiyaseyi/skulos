<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions module, Phase 1e: the school's own custom application form,
 * sections grouped into steps. Adapts CustomFieldDefinition/Value's EAV
 * shape (same field types: text/textarea/number/date/select/checkbox —
 * deliberately NOT a "file" type here, document upload is its own richer
 * subsystem with per-document approval, Phase 1f, not a bare form field).
 * Conditional field display ("show X only if Y = value") is explicitly
 * out of scope for this pass, a real rules engine, not a quick add — see
 * the approved plan.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_form_sections', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->unsignedInteger('step')->default(1);
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('admission_form_fields', function (Blueprint $table) {
            $table->id();
            $table->foreignId('admission_form_section_id')->nullable()->constrained('admission_form_sections')->nullOnDelete();
            $table->string('label');
            $table->string('field_key');
            $table->string('type');
            $table->json('options')->nullable();
            $table->boolean('is_required')->default(false);
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('admission_form_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('applicant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('admission_form_field_id')->constrained()->cascadeOnDelete();
            $table->text('value')->nullable();
            $table->timestamps();

            $table->unique(['applicant_id', 'admission_form_field_id'], 'admission_form_values_unique_field');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_form_values');
        Schema::dropIfExists('admission_form_fields');
        Schema::dropIfExists('admission_form_sections');
    }
};
