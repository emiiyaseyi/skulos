<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Sectioned papers (user directive 2026-08-01): when an assessment
 * draws from several question banks, the taker should be told which
 * bank they are entering before its questions begin, and the time
 * limit may either cover the whole paper or be split per bank.
 *
 * - cbt_assessments.timer_mode: 'total' (one clock for everything, the
 *   existing behaviour and the default) or 'per_section' (each section
 *   gets its own clock and its own hard stop).
 * - cbt_assessments.score_aggregation: 'sum' (every question's points
 *   added together, the default and existing behaviour) or
 *   'average_sections' (each section marked out of its own total, then
 *   every section's percentage weighted equally into the final score,
 *   regardless of how many marks that section actually carried).
 * - cbt_assessments.pass_message / fail_message: admin-set text shown
 *   on the result screen once a pass_mark is configured and the score
 *   clears (or does not clear) it — e.g. an admission assessment
 *   telling a passing applicant "You have successfully moved to the
 *   next stage of the application process." Both optional; the result
 *   screen falls back to a plain generic line when left blank.
 * - cbt_question_pools.label / duration_minutes: the section's shown
 *   name (falling back to the category's own name) and, under
 *   per_section timing, its own allowance.
 * - cbt_session_questions.pool_id / section_label / section_order:
 *   frozen onto the taker's own paper alongside the questions, so the
 *   sections they saw can be reconstructed exactly on resume and on
 *   review even if a pool is later renamed or deleted.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->string('timer_mode')->default('total')->after('duration_minutes');
            $table->string('score_aggregation')->default('sum')->after('timer_mode');
            $table->text('pass_message')->nullable()->after('pass_mark');
            $table->text('fail_message')->nullable()->after('pass_message');
        });

        Schema::connection('tenant')->table('cbt_question_pools', function (Blueprint $table) {
            $table->string('label')->nullable()->after('category_id');
            $table->unsignedInteger('duration_minutes')->nullable()->after('points_each');
        });

        Schema::connection('tenant')->table('cbt_session_questions', function (Blueprint $table) {
            $table->foreignId('pool_id')->nullable()->after('question_id')->constrained('cbt_question_pools')->nullOnDelete();
            // Denormalised on purpose: the label must survive the pool
            // being renamed or removed, because it is part of the
            // record of what this taker was actually shown.
            $table->string('section_label')->nullable()->after('pool_id');
            $table->unsignedInteger('section_order')->default(0)->after('section_label');
        });
    }

    public function down(): void
    {
        Schema::connection('tenant')->table('cbt_session_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('pool_id');
            $table->dropColumn(['section_label', 'section_order']);
        });
        Schema::connection('tenant')->table('cbt_question_pools', function (Blueprint $table) {
            $table->dropColumn(['label', 'duration_minutes']);
        });
        Schema::connection('tenant')->table('cbt_assessments', function (Blueprint $table) {
            $table->dropColumn(['timer_mode', 'score_aggregation', 'pass_message', 'fail_message']);
        });
    }
};
