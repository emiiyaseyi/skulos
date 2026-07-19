<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Narrative Evaluation (user request 2026-07-18): "allows the admin...
 * to set a class or some classes to have the Narrative Evaluation style
 * of report where scores are not entered into the student report card
 * but more of Narrative Evaluation (comment based)". Per class, not
 * school wide, and available on every plan (not a plan_feature_catalog
 * gate) since it is a pedagogical setting, not a paid tier feature. The
 * comment-based entry mechanism (StudentSubjectComment) and the
 * "narrative" report card template already existed as a school wide
 * template choice; this makes that same template selectable per class
 * instead, so a school can mix a scored Primary/Secondary with a
 * narrative Creche/Reception.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->string('evaluation_style')->default('scores')->after('name');
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropColumn('evaluation_style');
        });
    }
};
