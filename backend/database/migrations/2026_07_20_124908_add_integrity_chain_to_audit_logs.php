<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tamper-evident hash chain for the platform audit_logs table (2026-07-20
 * audit log hardening). Each row stores a sha256 of its own fields plus
 * the previous row's hash (App\Services\Auditing\IntegrityChain), so any
 * row edited outside the immutable-by-design model (e.g. a raw SQL
 * UPDATE) breaks the chain from that point forward, detectable by
 * App\Services\Auditing\IntegrityChecker / `php artisan audit-logs:verify`.
 * Existing rows are backfilled by `php artisan audit-logs:build-chain`.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->string('previous_hash', 64)->nullable()->after('url');
            $table->string('hash', 64)->nullable()->after('previous_hash');
            $table->index('hash');
        });
    }

    public function down(): void
    {
        Schema::table('audit_logs', function (Blueprint $table) {
            $table->dropIndex(['hash']);
            $table->dropColumn(['previous_hash', 'hash']);
        });
    }
};
