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
     * Automated admissions pipeline (2026-08-06, user directive: "for new
     * students, they are to pay both optional and non optional fees for
     * that child"). A thin wrapper, not new balance math: owedForClass()
     * already sums both optional AND non-optional active/approved fee
     * items for a class/term, exactly what a brand new admission owes —
     * named separately here purely so the call site (newAdmissionFeesCleared
     * below, and anywhere else this pipeline reads it) says what it means.
     */
    public function newAdmissionOwedForClass(int $schoolClassId, Term $term): float
    {
        return $this->owedForClass($schoolClassId, $term);
    }

    /**
     * Whether $student (a pending_enrollment admission) has paid enough,
     * verified, to clear the new-admission fee gate — both optional and
     * non-optional items, so a payment covering only the non-optional
     * total does NOT clear it. Idempotent: sets admission_fees_cleared_at
     * once, the first time this returns true, never re-evaluates or
     * unsets it afterward (a fee item's amount/approval status changing
     * later must not retroactively un-clear an admission already in
     * progress through onboarding). Called opportunistically wherever a
     * payment is verified (PaymentController::applyDecision()).
     */
    public function newAdmissionFeesCleared(Student $student, Term $term): bool
    {
        if ($student->admission_fees_cleared_at) {
            return true;
        }

        $classId = $student->currentClassArm?->school_class_id;
        if (! $classId) {
            return false;
        }

        $owed = $this->newAdmissionOwedForClass($classId, $term);
        $paid = $this->paidByStudent($student->id);
        $cleared = $paid >= $owed;

        if ($cleared) {
            $student->forceFill(['admission_fees_cleared_at' => now()])->save();
        }

        return $cleared;
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
     * Per-child breakdown that visibly explains nonOptionalOutstandingPooled()'s
     * total (user directive 2026-07-28, reacting to the per-child popup
     * showing Chukwuemeka's raw 200000 paid against his own 71500 owed:
     * "the status ought to show 71,500.00 paid and the balance from the
     * 200,000 spill to other children"). nonOptionalOutstandingPerChild()
     * above shows each child's own RAW payments, correct but not
     * self-explanatory once a family pools its balance; this instead
     * waterfalls each child's own payment against their own fee first,
     * then carries any surplus down to the next child who still owes
     * (in the same order the children are returned in), so the numbers
     * shown per row visibly explain, not just state, the pooled total.
     * 'paid' and 'due' here always sum to the same combined
     * owed/pooled-due nonOptionalOutstandingPooled() returns; 'owed' is
     * still each child's own real figure, unchanged.
     *
     * @return \Illuminate\Support\Collection<int, array{student: Student, owed: float, paid: float, due: float}>
     */
    public function nonOptionalOutstandingPerChildPooled(User $guardianUser, ?Term $term = null): \Illuminate\Support\Collection
    {
        $rows = $this->nonOptionalOutstandingPerChild($guardianUser, $term)->values();

        $surplus = $rows->sum(fn ($row) => max(0, $row['paid'] - $row['owed']));

        return $rows->map(function ($row) use (&$surplus) {
            $selfPaid = min($row['owed'], $row['paid']);
            $remaining = $row['owed'] - $selfPaid;

            if ($remaining > 0 && $surplus > 0) {
                $applied = min($remaining, $surplus);
                $selfPaid += $applied;
                $remaining -= $applied;
                $surplus -= $applied;
            }

            return ['student' => $row['student'], 'owed' => $row['owed'], 'paid' => $selfPaid, 'due' => $remaining];
        });
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
     * 'owed' and 'paid' are each child's own raw figures (user directive
     * 2026-07-28: the "Fees due per child" popup should show "the
     * amount to be pay by each child, the amount paid... and a
     * status"), 'due' stays the already-clamped max(0, owed-paid) used
     * everywhere else this method is called.
     *
     * @return \Illuminate\Support\Collection<int, array{student: Student, owed: float, paid: float, due: float}>
     */
    public function nonOptionalOutstandingPerChild(User $guardianUser, ?Term $term = null): \Illuminate\Support\Collection
    {
        $term ??= Term::current()->first();
        $students = Student::whereIn('id', StudentGuardian::where('guardian_user_id', $guardianUser->id)->pluck('student_id'))
            ->with('currentClassArm.schoolClass')->get();

        if (! $term) {
            return $students->map(fn (Student $s) => ['student' => $s, 'owed' => 0.0, 'paid' => 0.0, 'due' => 0.0]);
        }

        $owedByClass = [];

        return $students->map(function (Student $student) use ($term, &$owedByClass) {
            $classId = $student->currentClassArm?->school_class_id;
            $owed = $classId ? ($owedByClass[$classId] ??= $this->nonOptionalOwedForClass($classId, $term)) : 0.0;
            $paid = $this->paidByStudent($student->id);

            return ['student' => $student, 'owed' => $owed, 'paid' => $paid, 'due' => max(0, $owed - $paid)];
        });
    }
}
