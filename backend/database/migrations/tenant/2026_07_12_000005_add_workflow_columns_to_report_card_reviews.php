<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Extends the report card review workflow (user directive 2026-07-12,
 * "Intelligent Assessment & Report Card Workflow.docx"): a configurable
 * multi-stage review chain (review_stage tracks where in
 * config('skulos.report_card_review_chain')/the tenant Setting override
 * a review currently is), a promotion recommendation captured at the
 * class teacher stage, and the Locked/Archived states added to
 * ReportCardReview.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('report_card_reviews', function (Blueprint $table) {
            $table->unsignedTinyInteger('review_stage')->default(0)->after('status');
            $table->string('promotion_recommendation')->nullable()->after('notes');
            $table->text('promotion_reason')->nullable()->after('promotion_recommendation');
            $table->timestamp('locked_at')->nullable()->after('released_at');
            $table->timestamp('archived_at')->nullable()->after('locked_at');
        });
    }

    public function down(): void
    {
        Schema::table('report_card_reviews', function (Blueprint $table) {
            $table->dropColumn(['review_stage', 'promotion_recommendation', 'promotion_reason', 'locked_at', 'archived_at']);
        });
    }
};
