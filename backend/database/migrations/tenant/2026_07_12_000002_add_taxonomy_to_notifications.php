<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Notification Center taxonomy (user directed backlog item, 2026-07-12):
 * every notification gets a broad "type" (academic/finance/urgent/system,
 * see config('skulos.notification_categories')) on top of its existing
 * free-text category, plus the ability to be pinned or archived instead of
 * every notification living forever as one flat unread/read feed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->string('type')->default('system')->after('category');
            $table->timestamp('pinned_at')->nullable()->after('read_at');
            $table->timestamp('archived_at')->nullable()->after('pinned_at');
            $table->index('type');
            $table->index('archived_at');
        });
    }

    public function down(): void
    {
        Schema::table('notifications', function (Blueprint $table) {
            $table->dropIndex(['type']);
            $table->dropIndex(['archived_at']);
            $table->dropColumn(['type', 'pinned_at', 'archived_at']);
        });
    }
};
