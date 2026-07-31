<?php

namespace App\Http\Controllers\Admissions;

use App\Models\Tenant\AdmissionFormField;
use App\Models\Tenant\AdmissionFormSection;
use App\Models\Tenant\AdmissionFormValue;
use App\Models\Tenant\Applicant;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admissions module, Phase 1e: an applicant fills in the school's own
 * custom application form, one step at a time. Step navigation mirrors
 * SetupController's shape (a steps list + ?step= query param), adapted
 * for real persisted draft state on AdmissionFormValue rather than
 * reflecting existing records — an applicant can leave and come back,
 * nothing is lost.
 */
class ApplicationFormController extends Controller
{
    protected function steps(): array
    {
        AdmissionFormSection::ensureSeeded();

        return AdmissionFormSection::where('is_active', true)
            ->distinct()
            ->orderBy('step')
            ->pluck('step')
            ->values()
            ->all();
    }

    public function show(Request $request): \Illuminate\View\View|\Illuminate\Http\RedirectResponse
    {
        $school = $request->attributes->get('school');
        $applicant = Auth::guard('applicant')->user();
        $steps = $this->steps();

        if (empty($steps)) {
            // No custom fields configured — documents (Phase 1f) always
            // apply regardless of whether a school built a custom form,
            // so skip straight to that step rather than dead-ending back
            // at the dashboard.
            return redirect()->route('school.admissions.documents', ['school' => $school->slug]);
        }

        $step = (int) ($request->query('step') ?: $steps[0]);
        if (! in_array($step, $steps, true)) {
            $step = $steps[0];
        }

        $sections = AdmissionFormSection::where('step', $step)->where('is_active', true)
            ->with(['fields' => fn ($q) => $q->where('is_active', true)->orderBy('sequence')])
            ->orderBy('sequence')
            ->get();

        $existingValues = AdmissionFormValue::where('applicant_id', $applicant->id)
            ->pluck('value', 'admission_form_field_id');

        $stepIndex = array_search($step, $steps, true);

        return view('admissions.form-step', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'applicant' => $applicant,
            'sections' => $sections,
            'existingValues' => $existingValues,
            'step' => $step,
            'stepIndex' => $stepIndex,
            // +1: documents (Phase 1f) is always the wizard's true final
            // step now, chained automatically after the last custom-field
            // step, so a custom step is never the actual last one anymore.
            'totalSteps' => count($steps) + 1,
        ]);
    }

    public function save(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $request->attributes->get('school');
        $applicant = Auth::guard('applicant')->user();
        $steps = $this->steps();
        $step = (int) ($request->query('step') ?: ($steps[0] ?? 1));

        $fields = AdmissionFormField::whereHas('section', fn ($q) => $q->where('step', $step))
            ->where('is_active', true)
            ->get();

        $rules = [];
        foreach ($fields as $field) {
            if ($field->type === 'geo') {
                // The reusable Country/State/LGA picker posts three flat,
                // non-nested field names (not fields[{id}][...] — old()
                // only resolves dot notation, and the geo-picker component
                // calls old($countryName, ...) using its own name prop
                // verbatim, so a bracketed name would silently never
                // restore on a validation-error redisplay).
                $rules["field_{$field->id}_country"] = $field->is_required ? ['required', 'string', 'max:120'] : ['nullable', 'string', 'max:120'];
                $rules["field_{$field->id}_state"] = ['nullable', 'string', 'max:120'];
                $rules["field_{$field->id}_lga"] = ['nullable', 'string', 'max:120'];

                continue;
            }
            $rules["fields.{$field->id}"] = $field->is_required ? ['required', 'string', 'max:2000'] : ['nullable', 'string', 'max:2000'];
        }
        $data = $request->validate($rules);

        foreach ($fields as $field) {
            if ($field->type === 'geo') {
                $country = $data["field_{$field->id}_country"] ?? null;
                $state = $data["field_{$field->id}_state"] ?? null;
                $lga = $data["field_{$field->id}_lga"] ?? null;
                if (! $country && ! $state && ! $lga) {
                    AdmissionFormValue::where('applicant_id', $applicant->id)->where('admission_form_field_id', $field->id)->delete();

                    continue;
                }
                AdmissionFormValue::updateOrCreate(
                    ['applicant_id' => $applicant->id, 'admission_form_field_id' => $field->id],
                    ['value' => json_encode(['country' => $country, 'state' => $state, 'lga' => $lga])]
                );

                continue;
            }

            $value = $data['fields'][$field->id] ?? null;
            if ($value === null || $value === '') {
                AdmissionFormValue::where('applicant_id', $applicant->id)->where('admission_form_field_id', $field->id)->delete();

                continue;
            }
            AdmissionFormValue::updateOrCreate(
                ['applicant_id' => $applicant->id, 'admission_form_field_id' => $field->id],
                ['value' => $value]
            );
        }

        $stepIndex = array_search($step, $steps, true);
        $nextStep = $steps[$stepIndex + 1] ?? null;

        if ($nextStep) {
            return redirect()->route('school.admissions.form', ['school' => $school->slug, 'step' => $nextStep]);
        }

        // Documents (Phase 1f) is the wizard's own final step, chained
        // automatically once the custom-field steps are done.
        return redirect()->route('school.admissions.documents', ['school' => $school->slug]);
    }
}
