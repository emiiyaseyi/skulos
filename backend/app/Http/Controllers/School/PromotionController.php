<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\PromotionRun;
use App\Models\Tenant\PromotionRunItem;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
use App\Services\Academics\PromotionService;
use App\Services\Rbac\Rbac;
use App\Services\Results\ResultService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Admin-triggered student promotion (feedback list 8/7/2026, item 9), a
 * second, separate path alongside PromotionService::promote() which only
 * ever runs automatically at end-of-session via AcademicsController::
 * endTerm() — that flow is untouched. Here an admin picks a source term
 * to evaluate, confirms a threshold, runs it on demand, and gets back a
 * real, persisted, revisitable report.
 *
 * Scalar route ids are read via $request->route(...) rather than typed
 * method parameters — these routes sit under a domain wildcard
 * ({school}.<domain>), and Laravel resolves untyped scalar controller
 * parameters positionally against ALL route parameters including the
 * domain one, not by name.
 */
class PromotionController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function authorizeAction(Request $request, string $permission): void
    {
        abort_unless(
            app(Rbac::class)->check(Auth::user(), $this->school($request), $permission),
            403,
            'You do not have permission for this.'
        );
    }

    protected function terms(): \Illuminate\Support\Collection
    {
        return Term::with('session')->orderByDesc('starts_on')->get();
    }

    public function create(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.promote_students');
        $school = $this->school($request);

        $terms = $this->terms();
        $termId = $request->integer('term_id') ?: null;
        $term = ($termId ? $terms->firstWhere('id', $termId) : null) ?? $terms->firstWhere('is_current', true) ?? $terms->first();
        $threshold = (float) $request->input('threshold', 70);

        $preview = collect();
        if ($term) {
            $results = app(ResultService::class);
            foreach (ClassArm::with('schoolClass.section', 'arm')->get()->sortBy(fn (ClassArm $a) => $a->orderKey()) as $classArm) {
                $roster = Student::where('current_class_arm_id', $classArm->id)->where('status', Student::STATUS_ACTIVE)->get();
                if ($roster->isEmpty()) {
                    continue;
                }
                $averages = collect($results->classBroadsheet($classArm->id, $term->id))->keyBy('student_id');

                $wouldPromote = 0;
                $wouldRepeat = 0;
                $noResults = 0;
                foreach ($roster as $student) {
                    $row = $averages->get($student->id);
                    if (! $row) {
                        $noResults++;
                    } elseif ($row['average'] < $threshold) {
                        $wouldRepeat++;
                    } else {
                        $wouldPromote++;
                    }
                }

                $preview->push([
                    'label' => $classArm->label(),
                    'student_count' => $roster->count(),
                    'would_promote' => $wouldPromote,
                    'would_repeat' => $wouldRepeat,
                    'no_results' => $noResults,
                ]);
            }
        }

        $existingRun = $term ? PromotionRun::where('term_id', $term->id)->orderByDesc('id')->first() : null;

        return view('school.promotions.create', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'terms' => $terms,
            'term' => $term,
            'threshold' => $threshold,
            'preview' => $preview,
            'existingRun' => $existingRun,
        ]);
    }

    public function store(Request $request, PromotionService $promotions): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.promote_students');
        $school = $this->school($request);

        $data = $request->validate([
            'term_id' => ['required', 'exists:tenant.terms,id'],
            'to_term_id' => ['nullable', 'exists:tenant.terms,id'],
            'threshold' => ['required', 'numeric', 'min:0', 'max:100'],
        ]);

        $term = Term::findOrFail($data['term_id']);
        $toTerm = ! empty($data['to_term_id']) ? Term::find($data['to_term_id']) : null;

        $run = $promotions->runManual($term, (float) $data['threshold'], $toTerm, Auth::id());

        return redirect()->route('school.promotions.show', ['school' => $school->slug, 'runId' => $run->id])
            ->with('status', "Promotion run complete: {$run->promoted_count} promoted, {$run->repeated_count} repeated, {$run->graduated_count} graduated, {$run->skipped_count} skipped.");
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.promote_students');
        $school = $this->school($request);

        $runs = PromotionRun::with('term.session', 'toTerm')->orderByDesc('id')->get();

        return view('school.promotions.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'runs' => $runs,
        ]);
    }

    public function show(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.promote_students');
        $school = $this->school($request);

        $run = PromotionRun::with('term.session', 'toTerm', 'items.student', 'items.fromClassArm', 'items.toClassArm')
            ->findOrFail($request->route('runId'));

        $byClassArm = $run->items->groupBy('from_class_arm_id')->map(function ($items) {
            $first = $items->first();

            return [
                'label' => $first->fromClassArm?->label() ?? 'Unknown class',
                'total' => $items->count(),
                'promoted' => $items->where('outcome', PromotionRunItem::OUTCOME_PROMOTED)->count(),
                'repeated' => $items->where('outcome', PromotionRunItem::OUTCOME_REPEATED)->count(),
                'graduated' => $items->where('outcome', PromotionRunItem::OUTCOME_GRADUATED)->count(),
                'not_moved' => $items->whereNotIn('outcome', [PromotionRunItem::OUTCOME_PROMOTED, PromotionRunItem::OUTCOME_GRADUATED])->count(),
            ];
        })->values();

        $notMoved = $run->items->whereNotIn('outcome', [PromotionRunItem::OUTCOME_PROMOTED, PromotionRunItem::OUTCOME_GRADUATED])->values();

        return view('school.promotions.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'run' => $run,
            'byClassArm' => $byClassArm,
            'notMoved' => $notMoved,
        ]);
    }
}
