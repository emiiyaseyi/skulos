<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtSession;
use App\Models\Tenant\Student;
use App\Services\Cbt\AssessmentAvailabilityService;
use App\Services\Rbac\FamilyScope;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A parent's own view of assessments (user directive 2026-08-05:
 * "parent view of assessments should be for each child, enter each
 * child that has assessment, the child can take it from the parent
 * end... each child card shows if a child has an assessment, how many,
 * completed, left etc. and when parent opens each one can see the
 * complete details, score, pass mark, etc").
 *
 * Deliberately a SEPARATE screen from CbtTakeController::available(),
 * not a reuse of it: that controller always takes `Auth::user()` as
 * the taker, which for a parent is the parent's own account, not any
 * child's — a parent has never been enrolled in a class arm or added
 * as an explicit taker, so calling it as a parent would either show
 * nothing meaningful or (for an assessment with no targeting at all)
 * incorrectly "fall open" as if the parent themselves could take it.
 * "the child can take it from the parent end" is read as "the parent
 * can see and open into it," not literally sit the exam as the child —
 * CbtSession is keyed to one specific takeable (a School\User), and
 * this app has no shared-device/proxy-taking concept anywhere else.
 */
class CbtParentAssessmentController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    /** @return \Illuminate\Support\Collection<int, Student> */
    protected function children(Request $request, FamilyScope $familyScope): \Illuminate\Support\Collection
    {
        $school = $this->school($request);
        $studentIds = $familyScope->studentIds(Auth::user(), $school);
        abort_if($studentIds === null, 403, 'This screen is for parents only.');

        return Student::with('user', 'currentClassArm.schoolClass')
            ->whereIn('id', $studentIds)->orderBy('first_name')->get();
    }

    /** Every assessment a child (their own linked User account) is eligible for, same eligibility check CbtTakeController::available() uses. */
    protected function eligibleAssessments(Student $child): \Illuminate\Support\Collection
    {
        if (! $child->user) {
            return collect();
        }

        return CbtAssessment::where('audience', 'student')->where('is_active', true)->orderByDesc('id')->get()
            ->filter(fn (CbtAssessment $a) => $a->isEligible($child->user))
            ->values();
    }

    public function index(Request $request, FamilyScope $familyScope): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assessment_hub.view');
        $school = $this->school($request);
        $children = $this->children($request, $familyScope);

        $cards = $children->map(function (Student $child) use ($school) {
            $assessments = $this->eligibleAssessments($child);
            $sessions = $child->user
                ? CbtSession::where('takeable_type', \App\Models\User::class)->where('takeable_id', $child->user_id)
                    ->whereIn('assessment_id', $assessments->pluck('id'))->get()->keyBy('assessment_id')
                : collect();

            $completed = 0;
            $inProgress = 0;
            foreach ($assessments as $assessment) {
                $session = $sessions->get($assessment->id);
                if (! $session) {
                    continue;
                }
                if ($session->status === CbtSession::STATUS_IN_PROGRESS) {
                    $inProgress++;
                } elseif (in_array($session->status, [CbtSession::STATUS_SUBMITTED, CbtSession::STATUS_AUTO_SUBMITTED, CbtSession::STATUS_GRADED], true)) {
                    $completed++;
                }
            }

            return [
                'student' => $child,
                'total' => $assessments->count(),
                'completed' => $completed,
                'inProgress' => $inProgress,
                'left' => max(0, $assessments->count() - $completed - $inProgress),
            ];
        });

        return view('school.cbt.parent.index', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'cards' => $cards,
        ]);
    }

    public function child(Request $request, FamilyScope $familyScope, AssessmentAvailabilityService $availability): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assessment_hub.view');
        $school = $this->school($request);
        $children = $this->children($request, $familyScope);
        $child = $children->firstWhere('id', (int) $request->route('studentId'));
        abort_if(! $child, 404);

        $assessments = $this->eligibleAssessments($child);
        $sessions = $child->user
            ? CbtSession::where('takeable_type', \App\Models\User::class)->where('takeable_id', $child->user_id)
                ->whereIn('assessment_id', $assessments->pluck('id'))->get()->keyBy('assessment_id')
            : collect();

        $rows = $assessments->map(function (CbtAssessment $assessment) use ($sessions, $availability) {
            $session = $sessions->get($assessment->id);
            $scoreVisible = $session && $availability->resultsVisible($assessment, $session);

            return [
                'assessment' => $assessment,
                'session' => $session,
                'scoreVisible' => $scoreVisible,
                'passed' => ($scoreVisible && $assessment->pass_mark !== null && $session?->total_score !== null)
                    ? ((float) $session->total_score >= (float) $assessment->pass_mark)
                    : null,
            ];
        });

        return view('school.cbt.parent.child', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'student' => $child,
            'rows' => $rows,
        ]);
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }
}
