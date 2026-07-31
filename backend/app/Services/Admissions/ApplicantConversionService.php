<?php

namespace App\Services\Admissions;

use App\Models\School;
use App\Models\Tenant\Applicant;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Services\Accounts\AccountProvisioningService;
use App\Models\Tenant\Setting;

/**
 * Admissions module, Phase 1g: converts an offered, fee-paid applicant
 * into a real enrolled student, with zero duplicate data entry (user
 * directive 2026-07-30: "Transfer all application data automatically...
 * No manual duplication"). Everything the applicant already gave
 * (name, contact, class) carries over; only the real admission number
 * and target class ARM (the applicant only ever chose a class, not a
 * specific stream) are confirmed by an admin at conversion time, the
 * same two things Student creation already always requires a human to
 * decide, not guessed.
 */
class ApplicantConversionService
{
    public function __construct(protected AccountProvisioningService $accounts)
    {
    }

    public function convert(School $school, Applicant $applicant, string $admissionNumber, int $classArmId): Student
    {
        [$firstName, $lastName] = $applicant->first_name && $applicant->last_name
            ? [$applicant->first_name, $applicant->last_name]
            : $this->splitName($applicant->name);

        $student = Student::create([
            'admission_number' => $admissionNumber,
            'first_name' => $firstName,
            'other_names' => $applicant->other_names,
            'last_name' => $lastName,
            'date_of_birth' => $applicant->date_of_birth,
            'email' => $applicant->email,
            'current_class_arm_id' => $classArmId,
            'admission_session_id' => $applicant->session_id,
            'admitted_on' => now(),
            'status' => 'active',
        ]);

        $password = Setting::grab('default_student_password', config('skulos.default_student_password'));
        $syntheticEmail = \Illuminate\Support\Str::slug($student->admission_number, '-').'@students.'.$school->slug.'.skulos.local';
        $studentAccount = $this->accounts->provisionAuto($school, $student->fullName(), null, null, 'student', $password, $syntheticEmail);
        $student->update(['user_id' => $studentAccount['user']->id]);

        if ($applicant->email || $applicant->phone) {
            $guardian = $student->guardians()->create([
                'name' => $applicant->name, // the parent/guardian who applied, not the learner's own name — the applicant record only ever collected one contact, same limitation Phase 1e's form fields exist to close per-school
                'relationship' => 'Parent/Guardian',
                'email' => $applicant->email,
                'phone' => $applicant->phone,
                'is_primary' => true,
            ]);

            $guardianPassword = Setting::grab('default_parent_password', config('skulos.default_parent_password'));
            $guardianSyntheticEmail = 'guardian-'.$guardian->id.'@parents.'.$school->slug.'.skulos.local';
            // Reuses an already-provisioned account for this exact email/
            // phone (a guardian applying for a second child, or already a
            // parent at this school) rather than duplicating it — the
            // same reuse branch StudentController::storeGuardian() relies
            // on, real divergence bug already fixed there once before,
            // watched for here too.
            $guardianAccount = $this->accounts->provisionAuto($school, $guardian->name, $guardian->email, $guardian->phone, 'parent', $guardianPassword, $guardianSyntheticEmail);
            if (! $guardianAccount['isNew']) {
                $guardian->update([
                    'phone' => $guardianAccount['user']->phone ?: $guardian->phone,
                    'email' => ($guardianAccount['user']->email && ! str_ends_with($guardianAccount['user']->email, '.skulos.local')) ? $guardianAccount['user']->email : $guardian->email,
                ]);
            }
            $guardian->update(['guardian_user_id' => $guardianAccount['user']->id]);
        }

        $applicant->forceFill([
            'decision_status' => Applicant::DECISION_CONVERTED,
            'converted_student_id' => $student->id,
        ])->save();
        $applicant->advanceStage();

        return $student;
    }

    /**
     * Fallback only: an applicant created before the apply form collected
     * real first_name/last_name fields separately still only has one
     * combined "name" on file. First word is the first name, the rest is
     * the last name, an honest best-effort an admin can correct on the
     * student's own edit screen afterward, not a data loss.
     *
     * @return array{0: string, 1: string}
     */
    protected function splitName(string $name): array
    {
        $parts = preg_split('/\s+/', trim($name), 2);

        return [$parts[0] ?? $name, $parts[1] ?? ''];
    }
}
