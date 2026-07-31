<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Admissions module (user directive 2026-07-31: "admin should be able to
 * add new, edit existing one or reorder the admission pipeline as
 * peculiar to the school") — replaces the old config-catalog + tenant
 * Setting("admission_pipeline_chain") ordered-key-list with a real
 * per-tenant table, the same "nothing hardcoded — a school must be able
 * to customize their own" pattern grading_scales/comment_bank already
 * established. Investigated before making this change: pipeline_stage
 * (the applicant's index into the chain) has NO code anywhere gating on
 * a specific stage's identity, only on its numeric position
 * (Applicant::currentStageLabel()/isFinalStage()/advanceStage() are all
 * pure array-index arithmetic) — so a school freely renaming, adding, or
 * reordering stages is safe by construction, nothing downstream assumes
 * a fixed catalog of keys exists.
 *
 * Seeded once per school (AdmissionPipelineStage::ensureSeeded(), from
 * config('skulos.default_admission_pipeline_chain')'s existing 8 labels,
 * in the same order) so an already-provisioned school's pipeline reads
 * identically after this migration as before it, until an admin
 * deliberately edits it.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('admission_pipeline_stages', function (Blueprint $table) {
            $table->id();
            $table->string('label');
            $table->unsignedInteger('sequence');
            $table->timestamps();
        });

        $catalog = config('skulos.admission_pipeline_stages', []);
        $chain = \App\Models\Tenant\Setting::grab('admission_pipeline_chain') ?? config('skulos.default_admission_pipeline_chain', []);
        foreach (array_values($chain) as $i => $key) {
            \Illuminate\Support\Facades\DB::table('admission_pipeline_stages')->insert([
                'label' => $catalog[$key] ?? $key,
                'sequence' => $i,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('admission_pipeline_stages');
    }
};
