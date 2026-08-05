<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real bug caught by AssessmentStructureScreenTest's own new per-term
 * test (2026-08-05): the previous migration added term_id and scoped
 * the application-level uniqueness validation to it, but never touched
 * the actual DB-level `unique('name')` index from the original
 * assessment_components migration — a plain global constraint on name
 * alone. forkTerm() copying "First CA" from the universal set into a
 * term's own row (same name, different term_id) failed at the SQL
 * layer with a UNIQUE constraint violation despite passing application
 * validation. Replaced with a composite unique on [term_id, name], so
 * the same name is free to exist once per term AND once universally.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            $table->dropUnique('assessment_components_name_unique');
            $table->unique(['term_id', 'name']);
        });
    }

    public function down(): void
    {
        Schema::table('assessment_components', function (Blueprint $table) {
            $table->dropUnique(['term_id', 'name']);
            $table->unique('name');
        });
    }
};
