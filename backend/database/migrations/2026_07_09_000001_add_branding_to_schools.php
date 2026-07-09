<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Branding for each school's own welcome/login page, editable by the
 * school admin (logo already exists as logo_path). Lives on the platform
 * `schools` row so the tenant login page can render it before any tenant
 * connection is opened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('brand_color')->nullable()->after('logo_path');
            $table->string('welcome_headline')->nullable()->after('brand_color');
            $table->text('welcome_message')->nullable()->after('welcome_headline');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['brand_color', 'welcome_headline', 'welcome_message']);
        });
    }
};
