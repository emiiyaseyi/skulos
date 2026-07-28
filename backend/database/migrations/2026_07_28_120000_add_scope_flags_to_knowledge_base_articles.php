<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Per-article visibility, separate from the route-level flags
 * config('skulos.knowledge_base_start_routes')[route] already has (user
 * report 2026-07-28: "why are parents seeing for teachers knowledge
 * under their own help base"). Found live: the new "For Teachers"
 * category's "Viewing your timetable" and "Messaging a student's
 * guardian" articles both point at routes (school.timetable.mine,
 * school.chat.index) a parent ALSO genuinely holds the permission for,
 * for their own legitimate reason, so the route-level hidden_for_
 * family_scoped flag can't be used here without also hiding a future
 * genuinely parent-facing article that might reuse the same route. A
 * per-article flag decouples "is this specific article's wording and
 * content relevant to this viewer" from "does this viewer merely hold
 * the permission the linked screen requires", the same distinction the
 * route-level flags already draw, just one level more precise.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_base_articles', function (Blueprint $table) {
            $table->boolean('hidden_for_family_scoped')->default(false)->after('start_route');
            $table->boolean('hidden_for_teacher_scoped')->default(false)->after('hidden_for_family_scoped');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_base_articles', function (Blueprint $table) {
            $table->dropColumn(['hidden_for_family_scoped', 'hidden_for_teacher_scoped']);
        });
    }
};
