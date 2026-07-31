<?php

use App\Models\Tenant\AdmissionFormField;
use App\Models\Tenant\AdmissionFormSection;
use Illuminate\Database\Migrations\Migration;

/**
 * Admissions module (live feedback 2026-07-31: "Guardian Details can be
 * moved to step 2 and should ask for the role of the guardian, father,
 * mother or sponsor, applicant can add another guardian if needed").
 * Fixes up any school already seeded by the earlier default-form seed
 * (AdmissionFormSection::ensureSeeded() is a one-time no-op once a
 * school has any section, so config('skulos.default_admission_form_
 * sections')'s own updated shape alone would never reach a school that
 * ran the seed before this landed) — a school that hasn't been touched
 * yet just gets the new config default directly, nothing to fix here.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guardianSection = AdmissionFormSection::where('label', 'Guardian Details')->first();
        if (! $guardianSection) {
            return;
        }

        $guardianSection->update(['step' => 2]);

        if (! AdmissionFormField::where('admission_form_section_id', $guardianSection->id)->where('label', 'Guardian Role')->exists()) {
            AdmissionFormField::create([
                'admission_form_section_id' => $guardianSection->id,
                'label' => 'Guardian Role',
                'field_key' => AdmissionFormField::keyFromLabel('Guardian Role'),
                'type' => 'select',
                'options' => ['Father', 'Mother', 'Sponsor'],
                'is_required' => false,
                'sequence' => 0,
            ]);
        }

        if (! AdmissionFormSection::where('label', 'Second Guardian (optional)')->exists()) {
            $secondGuardian = AdmissionFormSection::create([
                'label' => 'Second Guardian (optional)',
                'step' => 2,
                'sequence' => (AdmissionFormSection::max('sequence') ?? 0) + 1,
            ]);

            foreach ([
                ['label' => "Guardian's Full Name", 'type' => 'text'],
                ['label' => 'Guardian Role', 'type' => 'select', 'options' => ['Father', 'Mother', 'Sponsor']],
                ['label' => 'Phone Number', 'type' => 'text'],
                ['label' => "Guardian's Occupation", 'type' => 'text'],
            ] as $i => $fieldData) {
                AdmissionFormField::create([
                    'admission_form_section_id' => $secondGuardian->id,
                    'label' => $fieldData['label'],
                    'field_key' => AdmissionFormField::keyFromLabel($fieldData['label']),
                    'type' => $fieldData['type'],
                    'options' => $fieldData['options'] ?? null,
                    'is_required' => false,
                    'sequence' => $i + 1,
                ]);
            }
        }
    }

    public function down(): void
    {
        // Content fix, not a schema change; not meaningfully reversible
        // (would need to guess which rows predate this migration).
    }
};
