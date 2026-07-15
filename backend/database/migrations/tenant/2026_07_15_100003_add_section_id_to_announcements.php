<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging targeting (scope 2026-07-15): a school owner/admin can target
 * a broadcast at teachers/parents/students of a whole section (e.g. all of
 * Nursery, or all of Secondary), not just one class arm or the whole
 * school. Nullable, only set when audience='section'.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->foreignId('section_id')->nullable()->after('class_arm_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('section_id');
        });
    }
};
