<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Self-hosted licensing: release management (2026-08-07). Set on a
 * self-hosted installation's OWN local School row by its own hourly
 * check-in (LicenseService::checkOne()) — the version string the
 * platform's latest published Release reports back, compared against
 * this install's own config('skulos.version') to decide whether to show
 * an "update available" banner. Meaningless on the platform's own School
 * rows (a self-hosted customer's record there is never itself checked
 * against a newer version — the reverse direction).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('latest_available_version')->nullable()->after('license_grace_started_at');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('latest_available_version');
        });
    }
};
