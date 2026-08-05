<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtFaceApproval;
use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtQuestionCategory;
use App\Models\Tenant\CbtSession;
use App\Models\Tenant\CbtSnapshot;
use App\Services\Cbt\AttemptControlService;
use App\Services\Cbt\CbtSessionService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Storage;

/**
 * The Admin Backend console — the reference repo's own siteadmin.php,
 * one screen with ten tabs covering every assessment in the school:
 * Overview, Assessments, Live Exams, High Risk, Suspended, Proctors,
 * Graders, Media Gallery, Question Bank, Branding. Sees and controls
 * EVERY staff member's assessments school-wide, not just the acting
 * user's own.
 *
 * Gated on assessment_hub.export (2026-08-05, corrected from .create —
 * user directive: "not all staff should have access to admin backend.
 * This is only for admin of the school to set"). .create/.update were
 * broadened to class-teacher/subject-teacher on 2026-08-02 so a teacher
 * could build THEIR OWN scoped assessments, which as a side effect also
 * opened this full, unscoped admin console to them, since it shared the
 * same gate. .export is never granted to a scoped teacher role (the
 * same "blanket access" signal ExaminationController::hasBlanketAccess()
 * and CbtAssessmentController::hasBlanketAccess() already use), and — like
 * every module permission — is a real, independently grantable code, so
 * an admin can hand it to another staff role from Role Management.
 */
class CbtAdminConsoleController extends Controller
{
    public const TABS = [
        'overview' => 'Overview',
        'assessments' => 'Assessments',
        'inprogress' => 'Live Exams',
        'highrisk' => 'High Risk',
        'suspended' => 'Suspended',
        'proctors' => 'Proctors',
        'graders' => 'Graders',
        'media' => 'Media Gallery',
        'questions' => 'Question Bank',
        'branding' => 'Branding',
    ];

    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), 'assessment_hub.export'),
            403,
            'You do not have permission for this.'
        );
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $tab = (string) $request->query('tab', 'overview');
        if (! array_key_exists($tab, self::TABS)) {
            $tab = 'overview';
        }

        return view('school.cbt.console.index', array_merge([
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'tab' => $tab,
            'tabs' => self::TABS,
            'counts' => $this->badgeCounts(),
        ], $this->dataFor($tab)));
    }

    /** Badge numbers shown on the tab strip itself. */
    protected function badgeCounts(): array
    {
        return [
            'assessments' => CbtAssessment::count(),
            'inprogress' => CbtSession::where('status', CbtSession::STATUS_IN_PROGRESS)->count(),
            'highrisk' => CbtSession::where('risk_level', 'high')->count(),
            'suspended' => CbtSession::where('status', CbtSession::STATUS_SUSPENDED)->count(),
            'media' => CbtSnapshot::count(),
            'questions' => CbtQuestion::count(),
            'faceApprovals' => CbtFaceApproval::where('status', CbtFaceApproval::STATUS_PENDING)->count(),
        ];
    }

    /** Only the active tab's data is queried — the console is one screen, not ten screens' worth of work per load. */
    protected function dataFor(string $tab): array
    {
        return match ($tab) {
            'overview' => [
                'stats' => [
                    'Assessments' => CbtAssessment::count(),
                    'Attempts' => CbtSession::count(),
                    'In progress' => CbtSession::where('status', CbtSession::STATUS_IN_PROGRESS)->count(),
                    'Finished' => CbtSession::whereIn('status', [CbtSession::STATUS_SUBMITTED, CbtSession::STATUS_AUTO_SUBMITTED, CbtSession::STATUS_GRADED])->count(),
                    'Suspended' => CbtSession::where('status', CbtSession::STATUS_SUSPENDED)->count(),
                    'High risk' => CbtSession::where('risk_level', 'high')->count(),
                ],
                'topRisk' => CbtSession::with(['assessment', 'takeable'])
                    ->where('status', CbtSession::STATUS_IN_PROGRESS)
                    ->orderByDesc('risk_score')->limit(10)->get(),
            ],
            'assessments' => [
                'assessments' => CbtAssessment::with(['subject', 'securityProfile'])
                    ->withCount(['assessmentQuestions', 'sessions'])
                    ->orderByDesc('id')->paginate(30)->withQueryString(),
            ],
            'inprogress' => [
                'sessions' => CbtSession::with(['assessment', 'takeable'])
                    ->where('status', CbtSession::STATUS_IN_PROGRESS)
                    ->orderByDesc('risk_score')->paginate(30)->withQueryString(),
            ],
            'highrisk' => [
                'sessions' => CbtSession::with(['assessment', 'takeable'])
                    ->where('risk_level', 'high')
                    ->orderByDesc('risk_score')->paginate(30)->withQueryString(),
            ],
            'suspended' => [
                'sessions' => CbtSession::with(['assessment', 'takeable'])
                    ->where('status', CbtSession::STATUS_SUSPENDED)
                    ->orderByDesc('suspended_at')->paginate(30)->withQueryString(),
            ],
            'media' => [
                'snapshots' => CbtSnapshot::with('session.takeable')
                    ->orderByDesc('captured_at')->paginate(48)->withQueryString(),
            ],
            'questions' => [
                'categories' => CbtQuestionCategory::withCount('questions')->orderBy('sort_order')->get(),
                'uncategorised' => CbtQuestion::whereNull('category_id')->count(),
            ],
            'branding' => [
                'assessments' => CbtAssessment::orderByDesc('id')->paginate(30)->withQueryString(),
            ],
            // Proctors and Graders are backed by their own tables, built
            // alongside the grading workflow; the tab renders an honest
            // "not built yet" state rather than a fake empty list.
            default => [],
        };
    }

    // ── Attempt control actions ─────────────────────────────────────

    public function suspend(Request $request, AttemptControlService $control): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $session = CbtSession::findOrFail($request->route('sessionId'));
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $control->suspend($session, Auth::id(), $data['reason'] ?? null);

        return back()->with('status', 'Attempt suspended.');
    }

    public function reinstate(Request $request, AttemptControlService $control): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $session = CbtSession::findOrFail($request->route('sessionId'));

        $control->reinstate($session);

        return back()->with('status', 'Attempt reinstated. The time lost while suspended has been given back.');
    }

    public function forceSubmit(Request $request, AttemptControlService $control, CbtSessionService $sessions): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $session = CbtSession::findOrFail($request->route('sessionId'));

        $control->forceSubmit($session, $sessions);

        return back()->with('status', 'Attempt submitted on the taker\'s behalf.');
    }

    public function pause(Request $request, AttemptControlService $control): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $assessment = CbtAssessment::findOrFail($request->route('assessmentId'));

        $assessment->paused_at ? $control->resumeAssessment($assessment) : $control->pauseAssessment($assessment);

        return back()->with('status', $assessment->fresh()->paused_at ? 'Assessment paused for every taker.' : 'Assessment resumed.');
    }

    public function releaseResults(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $assessment = CbtAssessment::findOrFail($request->route('assessmentId'));

        $assessment->forceFill(['results_released' => ! $assessment->results_released])->save();

        return back()->with('status', $assessment->results_released ? 'Results released to takers.' : 'Results held back from takers.');
    }

    public function updateBranding(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $assessment = CbtAssessment::findOrFail($request->route('assessmentId'));

        $assessment->update($request->validate([
            'brand_color' => ['nullable', 'string', 'max:20'],
            'brand_name' => ['nullable', 'string', 'max:150'],
        ]));

        return back()->with('status', 'Branding updated.');
    }

    /** Bulk delete of captured media — the reference's own Media Gallery cleanup, files removed from disk too. */
    public function deleteMedia(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $data = $request->validate([
            'snapshot_ids' => ['required', 'array', 'min:1'],
            'snapshot_ids.*' => ['exists:tenant.cbt_snapshots,id'],
        ]);

        $snapshots = CbtSnapshot::whereIn('id', $data['snapshot_ids'])->get();
        foreach ($snapshots as $snapshot) {
            Storage::disk('local')->delete($snapshot->path);
            $snapshot->delete();
        }

        return back()->with('status', $snapshots->count().' item(s) deleted.');
    }

    public function snapshotImage(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request);
        $snapshot = CbtSnapshot::findOrFail($request->route('snapshotId'));

        return Storage::disk('local')->response($snapshot->path);
    }
}
