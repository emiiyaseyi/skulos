<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real bug found live 2026-08-05: a bursar (no teacher/family scope at
 * all, but genuinely holding students.view/finance.view for their own
 * unrelated reason) was seeing the entire "For Parents" category —
 * hidden_for_family_scoped/hidden_for_teacher_scoped (2026-07-28) can
 * only ever HIDE an article from a scope it names, they have no way to
 * RESTRICT an article to only that scope, so any third role sharing the
 * same underlying permission slipped straight through. family_only/
 * teacher_only are the positive inverse of those two flags.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('knowledge_base_articles', function (Blueprint $table) {
            $table->boolean('family_only')->default(false)->after('hidden_for_teacher_scoped');
            $table->boolean('teacher_only')->default(false)->after('family_only');
        });
    }

    public function down(): void
    {
        Schema::table('knowledge_base_articles', function (Blueprint $table) {
            $table->dropColumn(['family_only', 'teacher_only']);
        });
    }
};
