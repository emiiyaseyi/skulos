<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * SKULOS Assessment Hub rework (user directive 2026-07-31, live, with
 * screenshots: "IT SHOULD HAVE THE SAME BACKEND AND FRONT END AS IT IS
 * IN LEARNGUARD"). Phase 2a's question bank was a simplification -
 * options stuffed into one JSON column, no categories, no partial
 * credit, no per-answer feedback. This brings it to the reference
 * repo's own `learnguard_qbank`/`_cats`/`_answers` shape:
 *
 *  - cbt_question_categories: a real category a question belongs to
 *    (the Question Bank screen's own left sidebar, with per-category
 *    counts, is built on this).
 *  - cbt_question_answers: one row per option, each with its own
 *    `fraction` (partial credit, 0-1) and `feedback` - replaces the
 *    `options`/`correct_answer` JSON pair for every objective type.
 *  - New columns on cbt_questions matching the reference field-for-
 *    field: name (a title distinct from the question text),
 *    shuffle_answers, general_feedback, marking_guidance (essay-only,
 *    shown to graders), case_sensitive (short answer), is_mandatory
 *    (always included when an assessment draws a random subset).
 *
 * The old `options`/`correct_answer` columns are deliberately KEPT and
 * backfilled FROM, not dropped, in this migration: doctrine/dbal isn't
 * installed here so a column drop on SQLite would mean a full table
 * rebuild, and keeping them costs nothing while making the data
 * migration below verifiable after the fact. They stop being read once
 * CbtQuestion switches to the answers relation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->create('cbt_question_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();
        });

        Schema::connection('tenant')->create('cbt_question_answers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('question_id')->constrained('cbt_questions')->cascadeOnDelete();
            $table->text('answer_text')->nullable();
            $table->decimal('fraction', 8, 5)->default(0);
            $table->text('feedback')->nullable();
            $table->unsignedInteger('sort_order')->default(0);
            $table->timestamps();

            $table->index('question_id');
        });

        Schema::connection('tenant')->table('cbt_questions', function (Blueprint $table) {
            $table->foreignId('category_id')->nullable()->after('id')->constrained('cbt_question_categories')->nullOnDelete();
            $table->string('name')->nullable()->after('category_id');
            $table->boolean('shuffle_answers')->default(true)->after('points');
            $table->text('general_feedback')->nullable()->after('shuffle_answers');
            $table->text('marking_guidance')->nullable()->after('general_feedback');
            $table->boolean('case_sensitive')->default(false)->after('marking_guidance');
            $table->boolean('is_mandatory')->default(false)->after('case_sensitive');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('cbt_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('category_id');
            $table->dropColumn(['name', 'shuffle_answers', 'general_feedback', 'marking_guidance', 'case_sensitive', 'is_mandatory']);
        });
        Schema::connection('tenant')->dropIfExists('cbt_question_answers');
        Schema::connection('tenant')->dropIfExists('cbt_question_categories');
    }
};
