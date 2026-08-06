<?php

namespace App\Services\Admissions;

use App\Mail\AdmissionOfferMail;
use App\Models\School;
use App\Models\Tenant\Applicant;
use Illuminate\Support\Facades\Mail;

/**
 * Automated admissions pipeline (2026-08-06): the applicant/parent-facing
 * side effect of AdmissionPipelineController::offer() deciding an
 * applicant — deliberately its own service, not folded into decide()
 * itself, since decide() is shared with waitlist()/reject() which must
 * NOT trigger an offer notice. Mail-only: no guardian User exists yet at
 * this point (that only happens once the offer is accepted, see
 * AdmissionAcceptanceService), so no in-app Notification is possible.
 * Same "email is optional, never block on its absence" rule the rest of
 * Admissions already follows (AdmissionPipelineController::nudge()) —
 * an applicant with no email on file simply gets no automatic notice,
 * an admin can still reach them via the existing manual nudge.
 */
class AdmissionOfferService
{
    public function sendOfferNotice(School $school, Applicant $applicant): void
    {
        if ($applicant->email) {
            Mail::to($applicant->email)->send(new AdmissionOfferMail($applicant, $school));
        }
    }
}
