<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtSession;
use App\Services\Cbt\EssayGradingService;
use App\Services\Cbt\ScoreReportingService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The three reporting screens the reference repo has:
 *
 *  - SCORES     every attempt at ONE assessment, sortable and
 *               filterable, with per-question state pills
 *  - RESULTS    every attempt across every assessment, for a
 *               school-wide view
 *  - MATRIX     one row per taker, one column per assessment — the
 *               "who has done what" grid
 *
 * All three resolve a taker's grade through ScoreReportingService, so
 * they can never disagree about what someone actually scored when an
 * assessment allows several attempts.
 */
class CbtScoreController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), 'assessment_hub.create'),
            403,
            'You do not have permission for this.'
        );
    }

    protected function staffNames(School $school): array
    {
        return $school->users()->pluck('name', 'users.id')->all();
    }

    // ── Scores: one assessment ──────────────────────────────────────

    public function scores(Request $request, ScoreReportingService $reporting): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);
        $assessment = CbtAssessment::findOrFail($request->route('assessmentId'));

        $sort = (string) $request->query('sort', 'name');
        $direction = $request->query('dir') === 'desc' ? 'desc' : 'asc';
        $status = (string) $request->query('status', '');
        $risk = (string) $request->query('risk', '');
        $search = trim((string) $request->query('search', ''));

        $sessions = CbtSession::with(['takeable', 'responses'])
            ->where('assessment_id', $assessment->id)
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->when($risk !== '', fn ($q) => $q->where('risk_level', $risk))
            ->get()
            ->when($search !== '', fn ($rows) => $rows->filter(
                fn (CbtSession $s) => str_contains(strtolower($s->takeable?->name ?? ''), strtolower($search))
            ));

        // Sorting happens in PHP because the two most useful columns
        // (taker name, score) live across a polymorphic relation and a
        // nullable column respectively, and an attempt list for one
        // assessment is small enough that this is never the bottleneck.
        $sessions = match ($sort) {
            'score' => $sessions->sortBy(fn ($s) => (float) ($s->total_score ?? -1)),
            'risk' => $sessions->sortBy('risk_score'),
            'submitted' => $sessions->sortBy(fn ($s) => $s->submitted_at?->timestamp ?? 0),
            default => $sessions->sortBy(fn ($s) => strtolower($s->takeable?->name ?? '')),
        };
        if ($direction === 'desc') {
            $sessions = $sessions->reverse();
        }

        $staffNames = $this->staffNames($school);

        return view('school.cbt.scores.index', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'assessment' => $assessment,
            'sessions' => $sessions->values(),
            'pills' => $sessions->mapWithKeys(fn (CbtSession $s) => [$s->id => $reporting->pillsFor($s, $staffNames)]),
            'sort' => $sort,
            'direction' => $direction,
            'status' => $status,
            'risk' => $risk,
            'search' => $search,
            'stateLabels' => ScoreReportingService::STATE_LABELS,
        ]);
    }

    /** CSV/TSV export of exactly what the filtered, sorted Scores view is showing. */
    public function exportScores(Request $request, ScoreReportingService $reporting): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request);
        $assessment = CbtAssessment::findOrFail($request->route('assessmentId'));
        $format = $request->query('format', 'csv');
        abort_unless(in_array($format, ['csv', 'tsv'], true), 404);

        $sessions = CbtSession::with('takeable')->where('assessment_id', $assessment->id)->orderBy('id')->get();

        $rows = [['Taker', 'Status', 'Score', 'Out of', 'Percentage', 'Risk', 'Started', 'Submitted']];
        foreach ($sessions as $session) {
            $rows[] = [
                $session->takeable?->name ?? 'Unknown',
                $session->status,
                $session->total_score,
                $session->max_score,
                $reporting->percentage((float) $session->total_score, (float) $session->max_score),
                $session->risk_score,
                $session->started_at?->format('Y-m-d H:i'),
                $session->submitted_at?->format('Y-m-d H:i'),
            ];
        }

        return $this->delimited($rows, 'scores-'.$assessment->id, $format);
    }

    // ── Results: school wide ────────────────────────────────────────

    public function results(Request $request, ScoreReportingService $reporting): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $assessmentId = $request->query('assessment');
        $status = (string) $request->query('status', '');

        $sessions = CbtSession::with(['assessment', 'takeable'])
            ->when($assessmentId, fn ($q) => $q->where('assessment_id', $assessmentId))
            ->when($status !== '', fn ($q) => $q->where('status', $status))
            ->orderByDesc('id')->paginate(50)->withQueryString();

        return view('school.cbt.scores.results', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'sessions' => $sessions,
            'assessments' => CbtAssessment::orderByDesc('id')->get(),
            'assessmentId' => $assessmentId,
            'status' => $status,
            'breakdown' => CbtSession::selectRaw('status, count(*) as total')->groupBy('status')->pluck('total', 'status'),
            'reporting' => $reporting,
        ]);
    }

    public function exportResults(Request $request, ScoreReportingService $reporting): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request);

        $sessions = CbtSession::with(['assessment', 'takeable'])->orderBy('id')->get();

        $rows = [['Assessment', 'Subject', 'Taker', 'Status', 'Score', 'Out of', 'Percentage', 'Risk', 'Submitted']];
        foreach ($sessions as $session) {
            $rows[] = [
                $session->assessment?->title,
                $session->assessment?->subject?->name,
                $session->takeable?->name ?? 'Unknown',
                $session->status,
                $session->total_score,
                $session->max_score,
                $reporting->percentage((float) $session->total_score, (float) $session->max_score),
                $session->risk_score,
                $session->submitted_at?->format('Y-m-d H:i'),
            ];
        }

        return $this->delimited($rows, 'results', 'csv');
    }

    /** Voids an attempt from the site-wide Results screen. */
    public function void(Request $request, EssayGradingService $grading): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $session = CbtSession::findOrFail($request->route('sessionId'));
        $data = $request->validate(['reason' => ['nullable', 'string', 'max:500']]);

        $grading->voidAttempt($session, Auth::id(), $data['reason'] ?? null);

        return back()->with('status', 'Attempt voided and scored zero.');
    }

    /** Grants one more attempt to a taker who has used them all. */
    public function grantRetry(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request);
        $session = CbtSession::with('assessment')->findOrFail($request->route('sessionId'));

        $schedule = \App\Models\Tenant\CbtSchedule::firstOrCreate([
            'assessment_id' => $session->assessment_id,
            'takeable_type' => $session->takeable_type,
            'takeable_id' => $session->takeable_id,
        ], ['created_by_user_id' => Auth::id()]);

        $schedule->increment('extra_attempts');

        return back()->with('status', 'One extra attempt granted.');
    }

    // ── Score Matrix: takers x assessments ──────────────────────────

    public function matrix(Request $request, ScoreReportingService $reporting): \Illuminate\View\View
    {
        $this->authorizeAction($request);
        $school = $this->school($request);

        $subjectId = $request->query('subject');
        $sort = (string) $request->query('sort', 'name');

        $assessments = CbtAssessment::with('subject')
            ->when($subjectId, fn ($q) => $q->where('subject_id', $subjectId))
            ->orderBy('subject_id')->orderBy('id')->get();

        [$rows, $takerNames] = $this->matrixRows($assessments, $reporting);

        $rows = match ($sort) {
            'total_desc' => $rows->sortByDesc('total'),
            'total_asc' => $rows->sortBy('total'),
            'average_desc' => $rows->sortByDesc('average'),
            'recent' => $rows->sortByDesc('last_completed'),
            default => $rows->sortBy(fn ($r) => strtolower($r['name'])),
        };

        return view('school.cbt.scores.matrix', [
            'school' => $school,
            'accent' => $school->branding()['color'] ?? null,
            'assessments' => $assessments,
            'rows' => $rows->values(),
            'subjects' => \App\Models\Tenant\Subject::orderBy('name')->get(),
            'subjectId' => $subjectId,
            'sort' => $sort,
        ]);
    }

    public function exportMatrix(Request $request, ScoreReportingService $reporting): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request);
        $assessments = CbtAssessment::orderBy('id')->get();
        [$rows] = $this->matrixRows($assessments, $reporting);

        $header = ['Taker'];
        foreach ($assessments as $assessment) {
            $header[] = $assessment->title;
        }
        $header[] = 'Total';
        $header[] = 'Average';
        $header[] = 'Last completed';

        $out = [$header];
        foreach ($rows->sortBy(fn ($r) => strtolower($r['name'])) as $row) {
            $line = [$row['name']];
            foreach ($assessments as $assessment) {
                $line[] = $row['scores'][$assessment->id]['score'] ?? '';
            }
            $line[] = $row['total'];
            $line[] = $row['average'];
            $line[] = $row['last_completed']?->format('Y-m-d H:i');
            $out[] = $line;
        }

        return $this->delimited($out, 'score-matrix', 'csv');
    }

    /**
     * Builds one row per taker who has attempted anything, with their
     * resolved grade per assessment.
     */
    protected function matrixRows($assessments, ScoreReportingService $reporting): array
    {
        $takers = CbtSession::with('takeable')
            ->whereIn('assessment_id', $assessments->pluck('id'))
            ->get()
            ->unique(fn (CbtSession $s) => $s->takeable_type.':'.$s->takeable_id);

        $names = [];
        $rows = $takers->map(function (CbtSession $sample) use ($assessments, $reporting, &$names) {
            $name = $sample->takeable?->name ?? 'Unknown';
            $names[$sample->takeable_id] = $name;

            $scores = [];
            $total = 0.0;
            $counted = 0;
            $lastCompleted = null;

            foreach ($assessments as $assessment) {
                $grade = $reporting->finalGrade($assessment, $sample->takeable_type, $sample->takeable_id);
                $scores[$assessment->id] = $grade;

                if ($grade['score'] !== null) {
                    $total += $grade['score'];
                    $counted++;
                }
            }

            $lastCompleted = CbtSession::where('takeable_type', $sample->takeable_type)
                ->where('takeable_id', $sample->takeable_id)
                ->whereNotNull('submitted_at')->max('submitted_at');

            return [
                'name' => $name,
                'takeable_type' => $sample->takeable_type,
                'takeable_id' => $sample->takeable_id,
                'scores' => $scores,
                'total' => round($total, 2),
                'average' => $counted ? round($total / $counted, 2) : null,
                'last_completed' => $lastCompleted ? \Illuminate\Support\Carbon::parse($lastCompleted) : null,
            ];
        });

        return [$rows, $names];
    }

    /** @param array<int, array<int, mixed>> $rows */
    protected function delimited(array $rows, string $filename, string $format): \Symfony\Component\HttpFoundation\Response
    {
        $separator = $format === 'tsv' ? "\t" : ',';
        $handle = fopen('php://temp', 'r+');
        foreach ($rows as $row) {
            fputcsv($handle, $row, $separator);
        }
        rewind($handle);
        $body = stream_get_contents($handle);
        fclose($handle);

        return response($body, 200, [
            // TSV is what spreadsheet software opens most reliably
            // without a delimiter prompt, which is why the reference
            // offers it alongside CSV.
            'Content-Type' => $format === 'tsv' ? 'text/tab-separated-values' : 'text/csv',
            'Content-Disposition' => 'attachment; filename="'.$filename.'.'.$format.'"',
        ]);
    }
}
