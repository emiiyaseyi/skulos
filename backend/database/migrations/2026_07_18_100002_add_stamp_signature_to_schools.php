<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * School-wide default stamp/signature for the report card (user request
 * 2026-07-18: "the school stamp and signature is to be uploaded for all
 * the school's section and classes but each class teacher can decide to
 * change it"). This is the school-wide default; the per-class-arm
 * override lives on the tenant class_arms table instead (see the tenant
 * migration 2026_07_18_100001_report_card_enrichment), since a class arm
 * only exists in the tenant database. Lives here on the platform schools
 * row alongside logo_path for the same reason branding does: no tenant
 * connection is guaranteed open everywhere a report card might render.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('stamp_path')->nullable()->after('welcome_message');
            $table->string('signature_path')->nullable()->after('stamp_path');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['stamp_path', 'signature_path']);
        });
    }
};
