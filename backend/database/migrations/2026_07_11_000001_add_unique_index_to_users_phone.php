<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school can now choose phone as its parent/staff identifier (tenant
 * Setting "parent_identifier"/"staff_identifier", user request
 * 2026-07-11), so phone needs the same platform-wide uniqueness guarantee
 * email already has, in case two different people would otherwise share
 * one phone number across the same shared User table. Nullable and
 * unique together is safe in both MySQL and SQLite: any number of NULL
 * rows are allowed, only an actual duplicate non-null value collides.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
    }
};
