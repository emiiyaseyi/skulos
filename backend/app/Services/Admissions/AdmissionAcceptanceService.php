<?php

namespace App\Services\Admissions;

use App\Mail\AdmissionAcceptanceMail;
use App\Models\School;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Student;
use App\Services\Accounts\AccountProvisioningService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;

/**
 * Automated admissions pipeline (user directive 2026-08-06): the parent's
 * own explicit accept/decline of an offer. accept() is the direct
 * analogue of ApplicantConversionService::convert() (same Student-field-
 * copy shape, guardian-creation-only-if-contact-info-present, guardian-
 * account-reuse-by-email/phone) but fires automatically the moment the
 * parent accepts rather than waiting on an admin's manual convert form,
 * and creates the Student DORMANT (status = pending_enrollment) rather
 * than active — see Student::STATUS_PENDING_ENROLLMENT's own docblock
 * for why. Wrapped in a transaction since considerably more now happens
 * atomically than the original manual path (two account provisions, a
 * Student row, and the Applicant's own decision fields all together).
 *
 * "Dormant until onboarded" applies to the STUDENT's status only, not
 * the guardian's login: the guardian account is fully active from this
 * point, since User::isMemberOf() hard-requires an active school_user
 * pivot just to authenticate at all, and the guardian must be able to
 * sign in and pay through the real Finance module immediately.
 */
class AdmissionAcceptanceService
{
    public function __construct(
        protected AccountProvisioningService $accounts,
        protected ClassArmAssignmentService $armAssignment,
    ) {
    }

    public function accept(School $school, Applicant $applicant): Student
    {
        return DB::transaction(function () use ($school, $applicant) {
            $schoolClass = $applicant->schoolClass;
            abort_if(! $schoolClass, 422, 'This application has no class on file.');

            $arm = $applicant->preferredClassArm ?: $this->armAssignment->lowestPopulationArm($schoolClass);
            abort_if(! $arm, 422, 'No class arm is available for this class yet — contact the school.');

            [$firstName, $lastName] = $applicant->first_name && $applicant->last_name
                ? [$applicant->first_name, $applicant->last_name]
                : $this->splitName($applicant->name);

            $student = Student::create([
                'admission_number' => Student::nextAdmissionNumber($school, $schoolClass, $applicant->session),
                'first_name' => $firstName,
                'other_names' => $applicant->other_names,
                'last_name' => $lastName,
                'date_of_birth' => $applicant->date_of_birth,
                'email' => $applicant->email,
                'current_class_arm_id' => $arm->id,
                'admission_session_id' => $applicant->session_id,
                'admitted_on' => now(),
                'status' => Student::STATUS_PENDING_ENROLLMENT,
            ]);

            $password = Setting::grab('default_student_password', config('skulos.default_student_password'));
            $syntheticEmail = Str::slug($student->admission_number, '-').'@students.'.$school->slug.'.skulos.local';
            $studentAccount = $this->accounts->provisionAuto($school, $student->fullName(), null, null, 'student', $password, $syntheticEmail);
            $student->update(['user_id' => $studentAccount['user']->id]);

            $guardianUser = null;
            if ($applicant->email || $applicant->phone) {
                $guardian = $student->guardians()->create([
                    'name' => $applicant->name,
                    'relationship' => 'Parent/Guardian',
                    'email' => $applicant->email,
                    'phone' => $applicant->phone,
                    'is_primary' => true,
                ]);

                $guardianPassword = Setting::grab('default_parent_password', config('skulos.default_parent_password'));
                $guardianSyntheticEmail = 'guardian-'.$guardian->id.'@parents.'.$school->slug.'.skulos.local';
                // Reuses an already-provisioned account for this exact
                // email/phone rather than duplicating it — the same reuse
                // branch ApplicantConversionService already relies on.
                $guardianAccount = $this->accounts->provisionAuto($school, $guardian->name, $guardian->email, $guardian->phone, 'parent', $guardianPassword, $guardianSyntheticEmail);
                if (! $guardianAccount['isNew']) {
                    $guardian->update([
                        'phone' => $guardianAccount['user']->phone ?: $guardian->phone,
                        'email' => ($guardianAccount['user']->email && ! str_ends_with($guardianAccount['user']->email, '.skulos.local')) ? $guardianAccount['user']->email : $guardian->email,
                    ]);
                }
                $guardian->update(['guardian_user_id' => $guardianAccount['user']->id]);
                $guardianUser = $guardianAccount['user'];
            }

            $applicant->forceFill([
                'offer_response' => Applicant::OFFER_ACCEPTED,
                'offer_responded_at' => now(),
                'decision_status' => Applicant::DECISION_CONVERTED,
                'converted_student_id' => $student->id,
            ])->save();
            $applicant->advanceStage();

            if ($guardianUser) {
                $this->notifyGuardianToPay($school, $guardianUser, $student);
            }

            return $student;
        });
    }

    public function decline(Applicant $applicant): void
    {
        $applicant->forceFill([
            'offer_response' => Applicant::OFFER_DECLINED,
            'offer_responded_at' => now(),
            'decision_status' => Applicant::DECISION_REJECTED,
            'decision_notes' => trim(($applicant->decision_notes ? $applicant->decision_notes.' ' : '').'Offer declined by applicant.'),
        ])->save();
    }

    /**
     * Reuses the existing 'payment_reminder' notification category (type
     * finance, already deep-linked to school.finance.payments.create) —
     * this is functionally exactly that: the guardian now owes fees for
     * a specific child, not a new kind of notification.
     */
    protected function notifyGuardianToPay(School $school, \App\Models\User $guardianUser, Student $student): void
    {
        Notification::create([
            'user_id' => $guardianUser->id,
            'category' => 'payment_reminder',
            'type' => Notification::typeFor('payment_reminder'),
            'title' => 'Pay school fees to complete enrollment',
            'body' => "You accepted the offer of admission for {$student->fullName()}. Pay their school fees to complete enrollment.",
        ]);

        if ($guardianUser->email && ! str_ends_with($guardianUser->email, '.skulos.local')) {
            Mail::to($guardianUser->email)->send(new AdmissionAcceptanceMail($student, $school));
        }
    }

    /** Fallback only: an applicant with no separate first_name/last_name on file. First word is the first name, the rest is the last name. */
    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? $name, $parts[1] ?? ''];
    }
}
