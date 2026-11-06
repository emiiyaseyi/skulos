<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * First-party visitor tracking for the marketing site, platform DB only
 * (the marketing pages exist before any school does). Built 2026-11-06,
 * direct instruction: "admin can also see who's coming to the platform,
 * how long they spent, etc, and if possible use it to run targeted
 * campaign". Deliberately not GA4/Clarity-backed (those stayed as the
 * external dashboards they already are) -- this is the one slice the
 * user asked to actually see inside SKULOS's own admin backend, and
 * confirmed reachable: the marketing site at skulosapp.com is served by
 * this same Laravel app (its /login route answers there), so a plain
 * first-party beacon calling back into /api/marketing/track needs no
 * Google Cloud credential or cross-origin workaround.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('marketing_page_views', function (Blueprint $table) {
            $table->id();
            $table->string('path', 255);
            $table->string('referrer', 500)->nullable();
            $table->string('utm_source', 100)->nullable();
            $table->string('utm_medium', 100)->nullable();
            $table->string('utm_campaign', 100)->nullable();
            // Persisted in the visitor's own browser localStorage, not a
            // real login -- identifies "the same browser, ever", not a
            // time-boxed session. Good enough to count unique visitors
            // and approximate how long one spends across the pages they
            // view in one sitting; not a precise analytics identity.
            $table->string('session_id', 64)->index();
            $table->string('user_agent', 255)->nullable();
            $table->timestamp('created_at')->useCurrent()->index();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('marketing_page_views');
    }
};
