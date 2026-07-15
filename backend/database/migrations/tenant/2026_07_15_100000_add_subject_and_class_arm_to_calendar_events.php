<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Simple exam calendar (scope decision 2026-07-15, user directive after
 * being asked: "Simple exam calendar... reuses the existing CalendarEvent
 * 'exam' category as the backbone", not a standalone exam-timetable model
 * with rooms/invigilators. Both columns are nullable and only meaningful
 * for category='exam' rows; every other category ignores them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->foreignId('subject_id')->nullable()->after('term_id')->constrained()->nullOnDelete();
            $table->foreignId('class_arm_id')->nullable()->after('subject_id')->constrained()->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('calendar_events', function (Blueprint $table) {
            $table->dropConstrainedForeignId('subject_id');
            $table->dropConstrainedForeignId('class_arm_id');
        });
    }
};
