<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Which login page design a school uses. Lives on the platform schools row
 * (like brand_color) because the login page renders before any tenant
 * connection is opened.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('login_layout')->nullable()->after('welcome_message');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn('login_layout');
        });
    }
};
