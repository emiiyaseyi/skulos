<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Mid-term report cards (2026-08-07): a mid-term and full-term review of
 * the same student's same term are now two independent rows through the
 * exact same review/approve/release/lock/archive workflow. Every
 * existing row defaults to 'full_term', an explicit, unchanged meaning
 * for every review ever created before this column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_card_reviews', function (Blueprint $table) {
            $table->string('report_type')->default('full_term')->after('term_id');
        });

        // Add-then-drop, each its own statement: student_id has a real
        // foreign key, and the old unique index (student_id, term_id)
        // is what supports it — MySQL/InnoDB refuses to drop the only
        // index covering a foreign-keyed column, so the replacement
        // index (which also starts with student_id) must exist first.
        // Same real bug shape as the timetable_entries migration, found
        // and fixed the same day (2026-08-15) before this one got the
        // chance to fail the same way in production.
        Schema::table('report_card_reviews', function (Blueprint $table) {
            $table->unique(['student_id', 'term_id', 'report_type']);
        });

        Schema::table('report_card_reviews', function (Blueprint $table) {
            $table->dropUnique('report_card_reviews_student_id_term_id_unique');
        });
    }

    public function down(): void
    {
        Schema::table('report_card_reviews', function (Blueprint $table) {
            $table->unique(['student_id', 'term_id']);
        });

        Schema::table('report_card_reviews', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'term_id', 'report_type']);
        });

        Schema::table('report_card_reviews', function (Blueprint $table) {
            $table->dropColumn('report_type');
        });
    }
};
