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

        Schema::table('report_card_reviews', function (Blueprint $table) {
            $table->dropUnique('report_card_reviews_student_id_term_id_unique');
            $table->unique(['student_id', 'term_id', 'report_type']);
        });
    }

    public function down(): void
    {
        Schema::table('report_card_reviews', function (Blueprint $table) {
            $table->dropUnique(['student_id', 'term_id', 'report_type']);
            $table->unique(['student_id', 'term_id']);
        });

        Schema::table('report_card_reviews', function (Blueprint $table) {
            $table->dropColumn('report_type');
        });
    }
};
