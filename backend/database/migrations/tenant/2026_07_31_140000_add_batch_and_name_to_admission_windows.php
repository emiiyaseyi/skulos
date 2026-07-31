<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Admissions module (live feedback 2026-07-31: "it should not show all
 * the windows per class but all merged into one, we can give each
 * application window a name and also make it able to edit a application
 * window"). Each AdmissionWindow row still represents one class (every
 * downstream lookup — isOpen() filtering, fee lookups, conversion —
 * already keys off session+class+campus per row, kept unchanged
 * deliberately), but rows opened together in one admin submission now
 * share a batch_uuid so the settings screen can render and edit them as
 * one named group instead of one row per class.
 *
 * (Doctrine DBAL isn't installed in this app, so a column type/
 * nullability ->change() isn't available here — the separate "acceptance
 * fee is optional" fix stays at the application layer instead:
 * AdmissionWindow::requiresAcceptanceFee() treats the existing 0 default
 * as "not configured", no schema change needed.)
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('admission_windows', function (Blueprint $table) {
            $table->string('name')->nullable()->after('id');
            $table->string('batch_uuid', 36)->nullable()->after('name')->index();
        });

        // Backfill: every already-existing window becomes its own
        // single-class batch, so nothing already open silently changes
        // grouping underneath an admin who never touches this again.
        DB::table('admission_windows')->whereNull('batch_uuid')->orderBy('id')->get()->each(function ($row) {
            DB::table('admission_windows')->where('id', $row->id)->update(['batch_uuid' => (string) Str::uuid()]);
        });
    }

    public function down(): void
    {
        Schema::table('admission_windows', function (Blueprint $table) {
            $table->dropColumn(['name', 'batch_uuid']);
        });
    }
};
