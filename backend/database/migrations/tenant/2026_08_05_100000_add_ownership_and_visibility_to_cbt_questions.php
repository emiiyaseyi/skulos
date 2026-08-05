<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User directive 2026-08-05: "teachers should only see the question
 * bank they created only and not the ones created by other users.
 * Let's add a feature under the question bank to mark if its for
 * general use or private use only." visibility defaults to 'general'
 * at the DB level so every already-existing question (built before
 * this feature existed, no real owner recorded) keeps behaving exactly
 * as it did before this migration — nothing already in a school's bank
 * silently vanishes from other teachers' view overnight. Going
 * forward, CbtQuestionBankController::store() defaults a NEW question
 * to 'private' unless the creating teacher explicitly marks it
 * general, matching the directive's own default expectation.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('cbt_questions', function (Blueprint $table) {
            $table->foreignId('created_by_staff_id')->nullable()->after('id')
                ->constrained('staff')->nullOnDelete();
            $table->string('visibility')->default('general')->after('is_active');
        });
    }

    public function down(): void
    {
        Schema::table('cbt_questions', function (Blueprint $table) {
            $table->dropConstrainedForeignId('created_by_staff_id');
            $table->dropColumn('visibility');
        });
    }
};
