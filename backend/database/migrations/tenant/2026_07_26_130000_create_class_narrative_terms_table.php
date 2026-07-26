<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Narrative Evaluation was a single permanent flag on school_classes
 * (evaluation_style), read live at render time with no term dimension: a
 * class marked narrative stayed narrative in every past and future term
 * until an admin flipped it back, which would also silently rewrite
 * already-generated report cards for prior terms. User request 2026-07-26:
 * "per-class-per-term toggle, re-toggleable across terms". This table
 * replaces evaluation_style as the source of truth: presence of a row for
 * (school_class_id, term_id) means narrative for that term only, absence
 * means scored (the default for any term never explicitly toggled).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('class_narrative_terms', function (Blueprint $table) {
            $table->id();
            $table->foreignId('school_class_id')->constrained()->cascadeOnDelete();
            $table->foreignId('term_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
            $table->unique(['school_class_id', 'term_id']);
        });

        // Backfill: a class already marked narrative under the old,
        // permanent column keeps narrative evaluation for the school's
        // current term only, so an already-in-progress term's report
        // cards don't silently flip back to scores the moment this
        // migration lands. Every other term (past or future) starts
        // blank, per class, exactly the re-toggleable behaviour this
        // table exists to provide.
        $currentTermId = DB::table('terms')->where('is_current', true)->value('id');
        if ($currentTermId) {
            $narrativeClassIds = DB::table('school_classes')->where('evaluation_style', 'narrative')->pluck('id');
            $now = now();
            foreach ($narrativeClassIds as $classId) {
                DB::table('class_narrative_terms')->insertOrIgnore([
                    'school_class_id' => $classId,
                    'term_id' => $currentTermId,
                    'created_at' => $now,
                    'updated_at' => $now,
                ]);
            }
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('class_narrative_terms');
    }
};
