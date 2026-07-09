<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Whether a class's subject is mandatory or optional, and examinable or
 * not (user request 2026-07-09: SS1-3 style classes do not have every
 * student on every subject). Defaults to true/true so existing rows and
 * younger sections, where everything is normally compulsory, need no
 * per-row change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('class_subject', function (Blueprint $table) {
            $table->boolean('is_mandatory')->default(true)->after('subject_id');
            $table->boolean('is_examinable')->default(true)->after('is_mandatory');
        });
    }

    public function down(): void
    {
        Schema::table('class_subject', function (Blueprint $table) {
            $table->dropColumn(['is_mandatory', 'is_examinable']);
        });
    }
};
