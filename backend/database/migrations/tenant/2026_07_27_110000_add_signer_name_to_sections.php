<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A section's own report card signature signer (user request 2026-07-27:
 * "signature signer should be the section head by default (for nursery
 * and primary - thats the head teacher) for secondary classes, that's
 * the Principal - Admin can change the name of the signer in the report
 * card setting for each of the section the school has"). Left unset,
 * ReportCardService falls back to a Head Teacher/Principal heuristic
 * based on the section's own name.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->string('signer_name')->nullable()->after('grading_scale_id');
        });
    }

    public function down(): void
    {
        Schema::table('sections', function (Blueprint $table) {
            $table->dropColumn('signer_name');
        });
    }
};
