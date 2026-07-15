<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging increment 3 (2026-07-15): links a tenant Announcement back to
 * the central `platform_broadcasts` row it was fanned out from (a
 * platform-owner broadcast reaching this school's owner/admin). No
 * cross-database foreign key (SQLite/MySQL cannot enforce one across
 * separate databases), just an unsigned id the platform side re-queries
 * per school to aggregate read receipts for the sender. Null for every
 * ordinary school-authored broadcast/announcement.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->unsignedBigInteger('platform_broadcast_id')->nullable()->after('image_path');
            $table->index('platform_broadcast_id');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('platform_broadcast_id');
        });
    }
};
