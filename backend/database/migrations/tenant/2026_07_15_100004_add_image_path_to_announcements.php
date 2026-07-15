<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Messaging increment 2 (2026-07-15): an optional light image on a
 * broadcast. Path only, never bytes, per the standing storage rule; the
 * file itself is converted to WebP and downsized by
 * App\Services\Media\ImageProcessingService before this column is ever
 * written.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->string('image_path')->nullable()->after('body');
        });
    }

    public function down(): void
    {
        Schema::table('announcements', function (Blueprint $table) {
            $table->dropColumn('image_path');
        });
    }
};
