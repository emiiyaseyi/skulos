<?php

namespace App\Services\Finance;

use App\Models\Tenant\FeeItem;
use App\Models\Tenant\Payment;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\Term;
use App\Models\User;

/**
 * What a class/family owes and has paid this term (user request
 * 2026-07-25, built for both the Payments class-status table and the
 * payment countdown popup, so the two never drift on what "owing" means).
 * A student's "paid" total is every one of their own verified payments
 * regardless of which specific fee item each was recorded against, the
 * same simplification classStatus() already used: a school cares whether
 * the family is square for the term, not fee-item-by-fee-item
 * reconciliation (which the installment schedule already covers for a
 * fee item that specifically opts into it).
 */
class FeeBalanceService
{
    /** Sum of every approved, active fee item for $term that applies to $schoolClassId. */
    public function owedForClass(int $schoolClassId, Term $term): float
    {
        return (float) FeeItem::where('term_id', $term->id)->active()
            ->where('approval_status', FeeItem::APPROVAL_APPROVED)
            ->with('schoolClasses')->get()
            ->filter(fn (FeeItem $f) => $f->appliesToClass($schoolClassId))
            ->sum('amount');
    }

    public function paidByStudent(int $studentId): float
    {
        return (float) Payment::where('student_id', $studentId)->where('status', Payment::STATUS_VERIFIED)->sum('amount');
    }

    /**
     * How much $guardianUser's linked children collectively still owe
     * this term, 0 if fully settled (or if they have no linked children,
     * or nothing is owed at all, e.g. no fee items configured).
     */
    public function outstandingForGuardian(User $guardianUser, ?Term $term = null): float
    {
        $term ??= Term::current()->first();
        if (! $term) {
            return 0.0;
        }

        $studentIds = StudentGuardian::where('guardian_user_id', $guardianUser->id)->pluck('student_id');
        if ($studentIds->isEmpty()) {
            return 0.0;
        }

        $students = Student::whereIn('id', $studentIds)->with('currentClassArm.schoolClass')->get();
        $owedByClass = [];
        $outstanding = 0.0;

        foreach ($students as $student) {
            $classId = $student->currentClassArm?->school_class_id;
            if (! $classId) {
                continue;
            }
            $owedByClass[$classId] ??= $this->owedForClass($classId, $term);
            $outstanding += max(0, $owedByClass[$classId] - $this->paidByStudent($student->id));
        }

        return $outstanding;
    }
}
