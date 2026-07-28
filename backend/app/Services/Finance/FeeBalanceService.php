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

    /**
     * Same as owedForClass(), restricted to non-optional fee items only
     * (user request 2026-07-26, "All Fees" payment plan on Submit Payment:
     * "based on the child or children selected... calculate the total
     * amount to be paid, this is only for non optional items"). An
     * optional item (uniform, sportswear) is something a parent opts into
     * separately, not part of the one combined "pay everything" total.
     */
    public function nonOptionalOwedForClass(int $schoolClassId, Term $term): float
    {
        return (float) FeeItem::where('term_id', $term->id)->active()
            ->where('approval_status', FeeItem::APPROVAL_APPROVED)
            ->where('is_optional', false)
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

    /**
     * Same as outstandingForGuardian(), non-optional fee items only (user
     * request 2026-07-27: "the fees due should be excluding non optional
     * items"), the same definition the parent dashboard's "Fees Due" tile
     * and the Submit Payment "All Fees" total already use. The payment
     * countdown popup switched to this too (2026-07-27, found live: it
     * still showed the old including-optional total after the dashboard
     * tile had already been fixed, "it did not factor in that the parent
     * has paid some amount before" — actually already correctly netted
     * against payments, the real bug was the optional-items inclusion).
     */
    public function nonOptionalOutstandingForGuardian(User $guardianUser, ?Term $term = null): float
    {
        return $this->nonOptionalOutstandingPerChild($guardianUser, $term)->sum('due');
    }

    /**
     * The family's one combined balance: total non-optional fees owed
     * across every linked child, minus every payment ever verified for
     * any of them, clamped once at the end (user directive 2026-07-27,
     * after directly rejecting the per-child clamped figure this file
     * used everywhere else: "calculate the total to be pay by all the
     * children... then deduct the 200000 that has been paid previously
     * from it, that should be the new balance"). Deliberately NOT the
     * same number as nonOptionalOutstandingForGuardian()/PerChild() above,
     * those clamp each child's own balance to zero before summing, which
     * stops one child's overpayment from ever offsetting a sibling's,
     * exactly the behaviour rejected here: a family is billed and pays
     * as one pool, so a surplus on one child's fees is real credit
     * toward the rest of the family's balance, not money that
     * disappears at that child's own zero floor.
     */
    public function nonOptionalOutstandingPooled(User $guardianUser, ?Term $term = null): float
    {
        $term ??= Term::current()->first();
        if (! $term) {
            return 0.0;
        }

        $students = Student::whereIn('id', StudentGuardian::where('guardian_user_id', $guardianUser->id)->pluck('student_id'))
            ->with('currentClassArm.schoolClass')->get();

        $owedByClass = [];
        $owed = 0.0;
        $paid = 0.0;
        foreach ($students as $student) {
            $classId = $student->currentClassArm?->school_class_id;
            $owed += $classId ? ($owedByClass[$classId] ??= $this->nonOptionalOwedForClass($classId, $term)) : 0.0;
            $paid += $this->paidByStudent($student->id);
        }

        return max(0, $owed - $paid);
    }

    /**
     * Per-child breakdown of what $guardianUser's linked children still
     * owe this term, non-optional fee items only (user request
     * 2026-07-27, parent dashboard "Fees Due" tile: "the fees due should
     * be excluding non optional items", matching the same "All Fees"
     * total the Submit Payment plan already uses, an optional item like
     * a uniform is something a parent opts into separately). Zero
     * children back an empty collection, not a guessed figure.
     *
     * @return \Illuminate\Support\Collection<int, array{student: Student, due: float}>
     */
    public function nonOptionalOutstandingPerChild(User $guardianUser, ?Term $term = null): \Illuminate\Support\Collection
    {
        $term ??= Term::current()->first();
        $students = Student::whereIn('id', StudentGuardian::where('guardian_user_id', $guardianUser->id)->pluck('student_id'))
            ->with('currentClassArm.schoolClass')->get();

        if (! $term) {
            return $students->map(fn (Student $s) => ['student' => $s, 'due' => 0.0]);
        }

        $owedByClass = [];

        return $students->map(function (Student $student) use ($term, &$owedByClass) {
            $classId = $student->currentClassArm?->school_class_id;
            $owed = $classId ? ($owedByClass[$classId] ??= $this->nonOptionalOwedForClass($classId, $term)) : 0.0;

            return ['student' => $student, 'due' => max(0, $owed - $this->paidByStudent($student->id))];
        });
    }
}
