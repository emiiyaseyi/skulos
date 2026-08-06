<?php

namespace App\Services\Admissions;

use App\Models\School;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\Setting;

/**
 * Automated admissions pipeline (user directive 2026-08-06: "confirm
 * that the school admin can change the content of the admission or
 * offer letter" — it could not, the wording was hardcoded in the Blade
 * view; this closes that gap). The two body paragraphs of the letter
 * are admin-editable per school (Admissions Settings screen), stored as
 * plain Setting rows with placeholder tokens resolved here at render
 * time — kept deliberately simple (two paragraphs, four tokens) rather
 * than a full template builder, matching this app's existing "footer
 * text" convention on report cards.
 */
class AdmissionLetterContentService
{
    /** @return array{intro: string, nextSteps: string} */
    public function render(School $school, Applicant $applicant): array
    {
        $intro = Setting::grab('admission_letter_intro') ?? config('skulos.default_admission_letter_intro');
        $nextSteps = Setting::grab('admission_letter_next_steps') ?? config('skulos.default_admission_letter_next_steps');

        return [
            'intro' => $this->fill($intro, $school, $applicant),
            'nextSteps' => $this->fill($nextSteps, $school, $applicant),
        ];
    }

    protected function fill(string $template, School $school, Applicant $applicant): string
    {
        return strtr($template, [
            '{applicant_name}' => $applicant->name,
            '{school_name}' => $school->name,
            '{class}' => $applicant->schoolClass?->name ?? 'the requested',
            '{session}' => $applicant->session?->name ?? 'the upcoming session',
        ]);
    }
}
