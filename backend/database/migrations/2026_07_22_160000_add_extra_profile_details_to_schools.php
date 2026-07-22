<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * User request 2026-07-22: "Under School Profile, one should also be
 * able to upload the school logo there. Also one should be able to add
 * addition details there if needed." Logo upload is already a real,
 * working field (schools.logo_path, written by
 * SchoolPortalController::updateBranding()) just never surfaced on this
 * particular screen; the genuinely new part is these extra optional
 * institutional details.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->string('website')->nullable()->after('address');
            $table->string('alternate_phone')->nullable()->after('phone');
            $table->string('registration_number')->nullable()->after('motto');
            $table->unsignedSmallInteger('established_year')->nullable()->after('registration_number');
        });
    }

    public function down(): void
    {
        Schema::table('schools', function (Blueprint $table) {
            $table->dropColumn(['website', 'alternate_phone', 'registration_number', 'established_year']);
        });
    }
};
