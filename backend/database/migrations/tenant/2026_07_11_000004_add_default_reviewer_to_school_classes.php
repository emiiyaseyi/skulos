<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A default reviewer per class (user request 2026-07-11: "the option to
 * select a staff name who will act as the reviewer for all the reports
 * generated in that class"). When a report card is generated for a
 * student in this class, it is auto assigned to this staff member for
 * review instead of being left unassigned. Plain id with no foreign key,
 * matching the existing class_teacher_staff_id / assistant_teacher_staff_id
 * convention on the same tables.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->unsignedBigInteger('default_reviewer_staff_id')->nullable()->after('is_active');
            $table->index('default_reviewer_staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropIndex(['default_reviewer_staff_id']);
            $table->dropColumn('default_reviewer_staff_id');
        });
    }
};
