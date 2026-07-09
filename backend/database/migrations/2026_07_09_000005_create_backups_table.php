<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A record of one backup of one school's tenant database (user request
 * 2026-07-09: each school gets backup/restore, initiated by the school
 * admin or the platform admin, plus a scheduled default). Lives in the
 * platform database — deliberately not in the tenant database itself, so
 * the backup history and files stay reachable even if something is wrong
 * with the tenant database.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('backups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_id')->constrained()->cascadeOnDelete();
            $table->string('disk')->default('local');
            $table->string('file_path');
            $table->unsignedBigInteger('size_bytes')->default(0);
            $table->string('type'); // manual | scheduled
            $table->foreignId('initiated_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('restored_at')->nullable();
            $table->timestamps();
            $table->index(['school_id', 'created_at']);
        });

        Schema::table('schools', function (Blueprint $table) {
            $table->string('backup_frequency')->default('none')->after('status'); // none | daily | weekly | monthly
            $table->time('backup_time')->nullable()->after('backup_frequency');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['backup_frequency', 'backup_time']);
        });
        Schema::dropIfExists('backups');
    }
};
