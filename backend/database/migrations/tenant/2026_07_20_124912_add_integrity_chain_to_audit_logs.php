<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Tenant-side twin of database/migrations/2026_07_20_124908_add_integrity_
 * chain_to_audit_logs.php — see that file's docblock. Each school's own
 * audit_logs table gets its own independent hash chain.
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
