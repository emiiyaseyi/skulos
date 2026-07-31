<?php

use App\Models\Tenant\AdmissionFormField;
use App\Models\Tenant\AdmissionFormSection;
use Illuminate\Database\Migrations\Migration;

/**
 * Admissions module (live feedback 2026-07-31: "each Guardian Details is
 * missing phone number, email address, phone number is mandatory for
 * each one entered"). Fixes up any school already seeded by the earlier
 * default-form seed, same reasoning as the previous guardian-role
 * migration: ensureSeeded() is a one-time no-op, so the updated config
 * shape alone never reaches a school seeded before this landed.
 * Second Guardian's own phone/email stay optional (the whole section is
 * optional; conditional-required is out of scope, see
 * AdmissionFormSection's own docblock) — only the primary Guardian
 * Details section's phone becomes required here, matching the config
 * default it should have matched from the start.
 */
return new class extends Migration
{
    public function up(): void
    {
        $guardianSection = AdmissionFormSection::where('label', 'Guardian Details')->first();
        if ($guardianSection) {
            $phone = AdmissionFormField::where('admission_form_section_id', $guardianSection->id)->where('label', 'Phone Number')->first();
            if ($phone) {
                $phone->update(['is_required' => true]);
            } else {
                AdmissionFormField::create([
                    'admission_form_section_id' => $guardianSection->id,
                    'label' => 'Phone Number',
                    'field_key' => AdmissionFormField::keyFromLabel('Phone Number'),
                    'type' => 'text',
                    'is_required' => true,
                    'sequence' => 1,
                ]);
            }

            if (! AdmissionFormField::where('admission_form_section_id', $guardianSection->id)->where('label', 'Email Address')->exists()) {
                AdmissionFormField::create([
                    'admission_form_section_id' => $guardianSection->id,
                    'label' => 'Email Address',
                    'field_key' => AdmissionFormField::keyFromLabel('Email Address'),
                    'type' => 'text',
                    'is_required' => false,
                    'sequence' => 2,
                ]);
            }
        }

        $secondGuardianSection = AdmissionFormSection::where('label', 'Second Guardian (optional)')->first();
        if ($secondGuardianSection
            && ! AdmissionFormField::where('admission_form_section_id', $secondGuardianSection->id)->where('label', 'Email Address')->exists()) {
            AdmissionFormField::create([
                'admission_form_section_id' => $secondGuardianSection->id,
                'label' => 'Email Address',
                'field_key' => AdmissionFormField::keyFromLabel('Email Address'),
                'type' => 'text',
                'is_required' => false,
                'sequence' => (AdmissionFormField::where('admission_form_section_id', $secondGuardianSection->id)->max('sequence') ?? 0) + 1,
            ]);
        }
    }

    public function down(): void
    {
        // Content fix, not a schema change; not meaningfully reversible.
    }
};
