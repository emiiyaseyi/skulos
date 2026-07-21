<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backups of the shared platform database itself (schools, users, plans,
 * billing, licenses, ...) — a genuine gap found 2026-07-21 during a
 * deployment readiness review: every school's own tenant database has had
 * a real backup feature since 2026-07-09, but nothing backed up the one
 * database every school depends on. Deliberately its own table, not a
 * reuse of the existing Backup model: that model and its whole admin/
 * school UI are built specifically around "one backup belongs to one
 * school" (school_id is required throughout), which a platform level
 * backup does not fit.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_backups', function (Blueprint $table) {
            $table->id();
            $table->string('disk');
            $table->string('file_path');
            $table->unsignedBigInteger('size_bytes');
            $table->string('type'); // manual | scheduled
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('created_at')->nullable();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_backups');
    }
};
