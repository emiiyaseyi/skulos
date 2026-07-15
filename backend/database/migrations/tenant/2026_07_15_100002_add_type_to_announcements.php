<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Memo to Parents (scope decision 2026-07-15: a one-way targeted broadcast
 * to parents/students with read tracking, listed in the original raw
 * requirements as its own mandatory feature distinct from Announcements
 * and from Internal Messaging). Rather than a parallel model + delivery
 * path, a memo is an announcement with type='memo': it reuses the exact
 * same NotificationService::dispatchAnnouncement() delivery (which already
 * writes one Notification row per recipient carrying its own read_at), the
 * genuinely new part being that a memo surfaces those read receipts to the
 * sender. Existing rows default to 'announcement', preserving behaviour.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('type')->default('announcement')->after('body'); // announcement | memo
            $table->index('type');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropColumn('type');
        });
    }
};
