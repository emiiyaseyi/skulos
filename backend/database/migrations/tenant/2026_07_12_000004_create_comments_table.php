<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * The score-range comment bank (user directive 2026-07-12: "note that i
 * dont want anything hardcoded as you know"). config('skulos.
 * default_comment_bank') is only ever the seed catalog, this table is
 * the live, per-school, admin-editable source of truth, the same
 * config-seeds-a-tenant-table pattern grading_scales/grading_bands
 * already established.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->decimal('min_score', 5, 2);
            $table->decimal('max_score', 5, 2);
            $table->text('text');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
            $table->index(['min_score', 'max_score']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('comments');
    }
};
