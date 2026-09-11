<?php

namespace App\Http\Controllers\Admissions\Concerns;

use App\Models\School;
use App\Models\Tenant\AdmissionFormSection;

/**
 * The application wizard's own step list, shared by ApplicationFormController
 * (the custom-field steps) and DocumentUploadController (always the wizard's
 * final step) -- renders a clickable stepper nav across the whole thing
 * (2026-09-11, user directive: "applicant should be able to move from one
 * section to another without using the continue button... we should have
 * the name of each section at the top and user can click on any").
 *
 * Jumping directly to any step was already safe server-side --
 * ApplicationFormController::show()/save() never enforced sequential
 * order, only that the requested step number is a real, known one -- this
 * trait just makes that reachable from the UI instead of hidden behind a
 * query string nobody would think to type.
 */
trait HasWizardSteps
{
    protected function customStepNumbers(): array
    {
        AdmissionFormSection::ensureSeeded();

        return AdmissionFormSection::where('is_active', true)
            ->distinct()->orderBy('step')->pluck('step')->values()->all();
    }

    /**
     * @return list<array{label: string, url: string, current: bool}>
     */
    protected function wizardStepList(School $school, ?int $currentStepNumber): array
    {
        $list = [];

        foreach ($this->customStepNumbers() as $number) {
            // A step can group more than one AdmissionFormSection (e.g.
            // "Guardian Details" + "Second Guardian (optional)" both sit
            // on step 2) -- the first one, in sequence order, names the
            // step in the nav, same as it visually leads that page.
            $firstSection = AdmissionFormSection::where('step', $number)->where('is_active', true)
                ->orderBy('sequence')->first();

            $list[] = [
                'label' => $firstSection?->label ?? "Step {$number}",
                'url' => route('school.admissions.form', ['school' => $school->slug, 'step' => $number]),
                'current' => $currentStepNumber === $number,
            ];
        }

        // Documents (Phase 1f) is always the wizard's true final step,
        // chained automatically after the custom-field steps whether or
        // not a school built any -- it belongs in the nav even for a
        // school with zero custom sections.
        $list[] = [
            'label' => 'Documents',
            'url' => route('school.admissions.documents', ['school' => $school->slug]),
            'current' => $currentStepNumber === null,
        ];

        return $list;
    }
}
