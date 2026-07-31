<?php

use App\Models\Tenant\AdmissionPipelineStage;
use Illuminate\Database\Migrations\Migration;

/**
 * Admissions module (live user report 2026-08-01, with a screenshot of
 * greenfield's real pipeline: "the admission assessment is missing from
 * the admission pipeline"). AdmissionPipelineStage::ensureSeeded() is a
 * one-time no-op once a school has any stage, so the updated config
 * default alone never reaches an already-seeded school — same reasoning
 * as every other "insert a new default into an already-seeded catalog"
 * data-fix migration this session (guardian role, geo picker, etc).
 * Inserted right after "Documents Uploaded" (or appended if that stage
 * was itself renamed/removed by the school), shifting every later
 * stage's sequence down by one; a school that already has a stage with
 * this exact label is left untouched.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (AdmissionPipelineStage::where('label', 'Admission Assessment')->exists()) {
            return;
        }

        $stages = AdmissionPipelineStage::orderBy('sequence')->get();
        if ($stages->isEmpty()) {
            return; // never seeded at all yet — the next real visit seeds the updated default directly
        }

        $afterStage = $stages->firstWhere('label', 'Documents Uploaded');
        $insertAtSequence = $afterStage ? $afterStage->sequence + 1 : $stages->max('sequence') + 1;

        AdmissionPipelineStage::where('sequence', '>=', $insertAtSequence)->increment('sequence');

        AdmissionPipelineStage::create(['label' => 'Admission Assessment', 'sequence' => $insertAtSequence]);
    }

    public function down(): void
    {
        // Content fix, not a schema change; not meaningfully reversible.
    }
};
