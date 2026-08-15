<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Real, measured dashboard performance fix (2026-08-15, live on
 * greenfield's production data: 326 students / ~67k score rows). The
 * admin dashboard's own school-wide average took 82ms in a single
 * query -- the slowest statement in a 63-query, 620ms render -- and
 * the same shape of query repeats across ResultService's
 * averagePercentageForClassArms(), subjectPerformanceForTerm() and
 * gradeDistributionForTerm():
 *
 *   WHERE term_id = ? [AND class_arm_id IN (...)]
 *   GROUP BY student_id, subject_id  ->  SUM(score)
 *
 * The table's only existing index is [class_arm_id, subject_id,
 * term_id]. term_id sits third, and subject_id (second) is never part
 * of the filter, so only the leading class_arm_id prefix was usable --
 * and the admin dashboard passes EVERY class arm, which is the whole
 * table, so it degenerated into a full scan plus a temp table for the
 * grouping.
 *
 * Column order here matches that access pattern exactly: term_id first
 * (always filtered, and the most selective single column -- a school
 * accumulates terms indefinitely while scores per term stay bounded),
 * class_arm_id second (filtered when the caller scopes to classes),
 * then the two GROUP BY columns, then score itself so the aggregate is
 * answered from the index alone with no row lookups.
 *
 * The existing [class_arm_id, subject_id, term_id] index is
 * deliberately kept, not replaced: it still serves the per-class,
 * per-subject lookups (subjectStats(), the broadsheet) that a single
 * class arm's screens run constantly, where class_arm_id IS the
 * selective leading column.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->index(
                ['term_id', 'class_arm_id', 'student_id', 'subject_id', 'score'],
                'scores_term_arm_student_subject_idx'
            );
        });
    }

    public function down(): void
    {
        Schema::table('scores', function (Blueprint $table) {
            $table->dropIndex('scores_term_arm_student_subject_idx');
        });
    }
};
