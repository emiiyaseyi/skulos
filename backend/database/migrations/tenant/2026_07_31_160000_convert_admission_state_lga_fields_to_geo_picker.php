<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Admissions module (live feedback 2026-07-31: the default-seeded "State
 * of Origin"/"Local Government Area" fields were plain text inputs;
 * should reuse the app's existing reusable Country/State/LGA cascading
 * picker with search, same as Student/Staff forms already do). Only a
 * handful of schools could have been auto-seeded between that seed
 * landing and this fix (same session), but this converts any of them in
 * place rather than leaving stray plain-text fields behind: the
 * "State of Origin" field becomes type 'geo' (one combined field,
 * storing country/state/lga as one JSON value — see AdmissionFormField's
 * own docblock), and any separately-seeded "Local Government Area"
 * field's values are merged into it before being removed, not silently
 * dropped.
 */
return new class extends Migration
{
    public function up(): void
    {
        $stateField = DB::table('admission_form_fields')->where('field_key', 'state_of_origin')->first();
        if (! $stateField) {
            return;
        }

        DB::table('admission_form_fields')->where('id', $stateField->id)->update(['type' => 'geo']);

        $lgaField = DB::table('admission_form_fields')->where('field_key', 'local_government_area')->first();
        if (! $lgaField) {
            return;
        }

        $stateValues = DB::table('admission_form_values')->where('admission_form_field_id', $stateField->id)->get()->keyBy('applicant_id');
        $lgaValues = DB::table('admission_form_values')->where('admission_form_field_id', $lgaField->id)->get()->keyBy('applicant_id');

        foreach ($stateValues->keys()->merge($lgaValues->keys())->unique() as $applicantId) {
            $geo = json_encode([
                'country' => null,
                'state' => $stateValues[$applicantId]->value ?? null,
                'lga' => $lgaValues[$applicantId]->value ?? null,
            ]);

            DB::table('admission_form_values')->updateOrInsert(
                ['applicant_id' => $applicantId, 'admission_form_field_id' => $stateField->id],
                ['value' => $geo, 'updated_at' => now(), 'created_at' => now()]
            );
        }

        DB::table('admission_form_values')->where('admission_form_field_id', $lgaField->id)->delete();
        DB::table('admission_form_fields')->where('id', $lgaField->id)->delete();
    }

    public function down(): void
    {
        // Not meaningfully reversible (the LGA field row and its
        // separation from State of Origin's values are gone); a fresh
        // ensureSeeded() re-run on a school with zero fields left would
        // recreate the current (already correct) shape regardless.
    }
};
