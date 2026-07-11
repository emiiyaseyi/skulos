<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * "Retire" a house without deleting it (user request 2026-07-10):
 * distinct from removing a house outright, a retired house keeps its
 * history (its past members stay linked and it still shows in the
 * Houses list and is still downloadable) but drops out of every
 * assignment picker so it cannot be assigned to anyone new.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('houses', function (Blueprint $table) {
            $table->boolean('is_active')->default(true)->after('color');
        });
    }

    public function down(): void
    {
        Schema::table('houses', function (Blueprint $table) {
            $table->dropColumn('is_active');
        });
    }
};
