<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A class had no retire/reactivate concept at all (user request
 * 2026-07-11: "there is no way to delete, edit, rename, retire etc a
 * class"), matching the same `is_active` pattern Houses already has. Also
 * adds one assistant class teacher slot per class arm ("assistant class
 * teachers" in the same request), a plain id with no foreign key, the
 * same style `class_teacher_staff_id` already uses, so a class arm can
 * exist before staff do.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('sequence');
        });

        Schema::table('class_arms', function (Blueprint $table) {
            $table->unsignedBigInteger('assistant_teacher_staff_id')->nullable()->after('class_teacher_staff_id');
            $table->index('assistant_teacher_staff_id');
        });
    }

    public function down(): void
    {
        Schema::table('school_classes', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });

        Schema::table('class_arms', function (Blueprint $table) {
            $table->dropIndex(['assistant_teacher_staff_id']);
            $table->dropColumn('assistant_teacher_staff_id');
        });
    }
};
