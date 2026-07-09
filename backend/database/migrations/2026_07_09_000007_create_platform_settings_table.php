<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Platform level key/value configuration (mirrors the tenant `settings`
 * table, TD-007) — currently used for SMTP settings (user request
 * 2026-07-09) so the platform admin can configure outgoing mail without
 * editing .env. Nothing here is hardcoded into the mail config; this is
 * the admin editable source of truth, .env stays the fallback default.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('platform_settings', function (Blueprint $table) {
            $table->id();
            $table->string('key')->unique();
            $table->json('value')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('platform_settings');
    }
};
