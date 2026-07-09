<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Links a trial request to the school it became once approved (user
 * request 2026-07-09: approving a trial now provisions the school
 * automatically rather than only sending an email).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('trial_requests', function (Blueprint $table) {
            $table->foreignId('school_id')->nullable()->after('status')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('trial_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('school_id');
        });
    }
};
