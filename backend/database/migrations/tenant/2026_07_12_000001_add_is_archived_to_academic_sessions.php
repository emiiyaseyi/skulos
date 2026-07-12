<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Archive old academic sessions (Dbase recommendation, standing rule in
 * CLAUDE.md/tracker: "stay accessible but excluded from default/daily
 * queries once a newer session is current"). A session is never deleted,
 * only hidden from the default list once the school marks it done.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('academic_sessions', function (Blueprint $table) {
            $table->boolean('is_archived')->default(false)->after('is_current');
        });
    }

    public function down(): void
    {
        Schema::table('academic_sessions', function (Blueprint $table) {
            $table->dropColumn('is_archived');
        });
    }
};
