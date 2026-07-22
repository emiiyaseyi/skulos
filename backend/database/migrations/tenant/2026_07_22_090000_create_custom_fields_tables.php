<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admin-defined custom fields (user request 2026-07-22: "I WANT THE ADMIN
 * TO BE ABLE TO CREATE A NEW FIELD THAT IS NOT THERE BEFORE AND ASSIGN TO
 * STUDENTS, STAFF AND PARENTS"). A genuinely new field per CLAUDE.md's
 * "nothing hardcoded" rule cannot mean a new column added to students/
 * staff/student_guardians on demand (that needs a real migration per
 * school, per field), so this is a standard EAV pair instead: one
 * definition row describes the field (label, type, which of
 * student/staff/guardian it applies to), one value row per definition per
 * actual record. entity_types is a JSON array rather than a pivot table
 * since the same field (e.g. "Blood Group") can reasonably apply to more
 * than one entity kind at once.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('custom_field_definitions', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            // Stable, url/name safe key derived from the label at creation
            // time (kept even if the label is later edited), used as the
            // form field name and the lookup key into values.
            $table->string('field_key')->unique();
            $table->string('type', 20); // text|textarea|number|date|select|checkbox
            // Only meaningful for type=select: an ordered list of option
            // strings. Stored as JSON, not a separate options table — this
            // is admin-authored, small, and never queried on its own.
            $table->json('options')->nullable();
            $table->json('entity_types'); // subset of ['student','staff','guardian']
            $table->boolean('is_required')->default(false);
            $table->unsignedSmallInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        Schema::create('custom_field_values', function (Blueprint $table) {
            $table->id();
            $table->foreignId('custom_field_definition_id')->constrained('custom_field_definitions')->cascadeOnDelete();
            $table->string('entity_type', 20); // student|staff|guardian
            // Plain id, no FK: entity_type decides which table entity_id
            // points into (students, staff, or student_guardians), a real
            // polymorphic relation would need a matching morph type per
            // table, this is deliberately the simpler two-column form
            // since there are exactly three known entity kinds.
            $table->unsignedBigInteger('entity_id');
            $table->text('value')->nullable();
            $table->timestamps();
            $table->unique(['custom_field_definition_id', 'entity_type', 'entity_id'], 'custom_field_value_unique');
            $table->index(['entity_type', 'entity_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('custom_field_values');
        Schema::dropIfExists('custom_field_definitions');
    }
};
