<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * A school's own catalog of "Key Assessment Areas" for a Nursery/Early
 * Years style narrative report card (user directive 2026-09-21, sourced
 * from a real school's own report card, e.g. category "Literacy &
 * Phonics" -> skill "Letter Recognition & Sound"). Admin managed, the
 * same seed-then-DB-backed shape every other report card catalog in
 * this codebase already follows (BehaviouralTrait, comment bank,
 * grading scales) -- nothing here is read live from config, and nothing
 * is seeded automatically for a school that never opts into the
 * 'skills' narrative style (see SchoolSectionBranding.narrative_style).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('report_card_skill_areas', function (Blueprint $table) {
            $table->id();
            $table->string('category');
            $table->string('name');
            $table->unsignedInteger('sequence')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('report_card_skill_areas');
    }
};
