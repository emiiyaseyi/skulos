<?php

namespace App\Services\Admissions;

use App\Mail\AdmissionOnboardedMail;
use App\Models\School;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use Illuminate\Support\Facades\Mail;

/**
 * Automated admissions pipeline (user directive 2026-08-06): "the class
 * teacher is to onboard the child to the class after which the child is
 * added to the class like other children in the class." The one place
 * that ever flips a dormant Student out of pending_enrollment — see
 * Student::STATUS_PENDING_ENROLLMENT's own docblock for why nothing else
 * should.
 */
class ClassTeacherOnboardingService
{
    public function onboard(School $school, Student $student, int $onboardedByUserId, ?int $houseId = null): Student
    {
        abort_unless($student->status === Student::STATUS_PENDING_ENROLLMENT, 422, 'This student has already been onboarded.');
        abort_unless($student->admission_fees_cleared_at, 422, 'School fees have not been cleared for this student yet.');

        $student->forceFill([
            'status' => Student::STATUS_ACTIVE,
            'onboarded_at' => now(),
            'onboarded_by_user_id' => $onboardedByUserId,
            'house_id' => $houseId,
        ])->save();

        $this->notifyGuardians($school, $student);

        return $student;
    }

    protected function notifyGuardians(School $school, Student $student): void
    {
        $guardianUserIds = StudentGuardian::where('student_id', $student->id)
            ->whereNotNull('guardian_user_id')
            ->pluck('guardian_user_id');

        foreach ($guardianUserIds as $guardianUserId) {
            Notification::create([
                'user_id' => $guardianUserId,
                'category' => 'admission_onboarded',
                'type' => Notification::typeFor('admission_onboarded'),
                'title' => 'Enrollment complete',
                'body' => "{$student->fullName()}'s enrollment is complete. They have been added to {$student->currentClassArm?->label()}.",
            ]);
        }

        $guardianUser = $guardianUserIds->isNotEmpty()
            ? \App\Models\User::find($guardianUserIds->first())
            : null;
        if ($guardianUser?->email && ! str_ends_with($guardianUser->email, '.skulos.local')) {
            Mail::to($guardianUser->email)->send(new AdmissionOnboardedMail($student, $school));
        }
    }
}
