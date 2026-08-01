<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtGrader;
use App\Models\Tenant\CbtResponse;
use App\Models\Tenant\CbtSession;
use App\Services\Cbt\EssayGradingService;
use App\Services\Cbt\GradingDistributionService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * The grader's own workspace: the written answers waiting on them,
 * and the form to mark each one.
 *
 * Gated on assessment_hub.grade AND on actually being a CbtGrader —
 * the permission alone is not enough, because which attempts a person
 * may see depends on their layer and on how the assessment shares work
 * out (see GradingDistributionService::sessionsFor()).
 *
 * ANONYMITY IS ENFORCED SERVER-SIDE. When a grader is anonymous the
 * taker's name is never loaded into the view at all — they are only
 * ever "Candidate #<session id>". Hiding it in CSS would leave the
 * real name in the HTML source.
 */
class CbtGraderController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), 'assessment_hub.grade'),
            403,
            'You do not have permission for this.'
        );
    }

    /** The grader record for the current user on this assessment, preferring the assessment-specific row over a school-wide one. */
    protected function graderFor(CbtAssessment $assessment): CbtGrader
    {
        $grader = CbtGrader::where('user_id', Auth::id())
            ->where(fn ($q) => $q->where('assessment_id', $assessment->id)->orWhereNull('assessment_id'))
            ->orderByRaw('assessment_id is null')      // assessment-specific first
            ->orderByDesc('grade_layer')                // reviewer rights win over first-marker
            ->first();

        abort_unless($grader, 403, 'You have not been assigned as a grader for this assessment.');

        return $grader;
    }

    public function index(Request $request, GradingDistributionService $distribution): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        // Every assessment this user grades, with how much is outstanding.
        $graderRows = CbtGrader::where('user_id', Auth::id())->get();
        $assessments = CbtAssessment::whereIn('id', $graderRows->pluck('assessment_id')->filter())
            ->orWhereRaw($graderRows->whereNull('assessment_id')->isNotEmpty() ? '1=1' : '1=0')
            ->orderByDesc('id')->get();

        $queues = $assessments->map(function (CbtAssessment $assessment) use ($distribution, $graderRows) {
            $layer = $graderRows->where('assessment_id', $assessment->id)->max('grade_layer')
                ?: $graderRows->whereNull('assessment_id')->max('grade_layer')
                ?: CbtGrader::LAYER_FIRST;

            return [
                'assessment' => $assessment,
                'layer' => $layer,
                'outstanding' => $distribution->outstandingCount($assessment, Auth::id(), $layer),
            ];
        })->filter(fn ($row) => $row['assessment'] !== null)->values();

        return view('school.cbt.grader.index', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'queues' => $queues,
        ]);
    }

    public function queue(Request $request, GradingDistributionService $distribution): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $assessment = CbtAssessment::findOrFail($request->route('assessmentId'));
        $grader = $this->graderFor($assessment);

        $sessions = $distribution->sessionsFor($assessment, Auth::id(), $grader->grade_layer)
            ->with(['responses.question', 'takeable'])->get();

        // Split into what still needs marking and what is done, the same
        // two-bucket view the reference uses.
        $pending = $sessions->filter(fn (CbtSession $s) => $s->responses->contains(fn ($r) => $r->status === CbtResponse::STATUS_UNGRADED));
        $done = $sessions->reject(fn (CbtSession $s) => $s->responses->contains(fn ($r) => $r->status === CbtResponse::STATUS_UNGRADED));

        return view('school.cbt.grader.queue', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'assessment' => $assessment,
            'grader' => $grader,
            'pending' => $pending->values(),
            'done' => $done->values(),
        ]);
    }

    public function show(Request $request, EssayGradingService $grading): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $session = CbtSession::with(['assessment', 'responses.question.answers', 'takeable'])
            ->findOrFail($request->route('sessionId'));
        $grader = $this->graderFor($session->assessment);

        $written = $session->responses->filter(fn (CbtResponse $r) => ! $r->question->isObjective())->values();

        return view('school.cbt.grader.show', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'session' => $session,
            'assessment' => $session->assessment,
            'grader' => $grader,
            // Anonymity resolved here, server-side; the real name never
            // reaches the view for a blind grader.
            'takerLabel' => $grader->labelFor($session),
            'written' => $written,
            'histories' => $written->mapWithKeys(fn (CbtResponse $r) => [$r->id => $grading->historyFor($r)]),
            'maxScores' => $written->mapWithKeys(fn (CbtResponse $r) => [$r->id => $grading->maxScoreFor($r)]),
        ]);
    }

    public function grade(Request $request, EssayGradingService $grading): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $response = CbtResponse::with('session.assessment', 'question')->findOrFail($request->route('responseId'));
        $grader = $this->graderFor($response->session->assessment);

        $data = $request->validate([
            'score' => ['required', 'numeric', 'min:0'],
            'feedback' => ['nullable', 'string', 'max:2000'],
        ]);

        $grading->grade($response, Auth::id(), $grader->grade_layer, (float) $data['score'], $data['feedback'] ?? null);

        return back()->with('status', 'Mark saved.');
    }

    public function resetGrades(Request $request, EssayGradingService $grading): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $session = CbtSession::with('assessment')->findOrFail($request->route('sessionId'));
        $grader = $this->graderFor($session->assessment);
        // Only a reviewer may wipe marks and start the attempt over.
        abort_unless($grader->isReviewer(), 403, 'Only a second reviewer can reset an attempt for re-marking.');

        $count = $grading->resetGrades($session);

        return back()->with('status', "{$count} answer(s) returned to ungraded.");
    }

    public function void(Request $request, EssayGradingService $grading): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $session = CbtSession::with('assessment')->findOrFail($request->route('sessionId'));
        $grader = $this->graderFor($session->assessment);
        abort_unless($grader->isReviewer(), 403, 'Only a second reviewer can void an attempt.');

        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);
        $grading->voidAttempt($session, Auth::id(), $data['reason'] ?? null);

        return back()->with('status', 'Attempt voided and scored zero.');
    }
}
