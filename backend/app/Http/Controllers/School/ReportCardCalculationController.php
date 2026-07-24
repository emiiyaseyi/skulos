<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use App\Services\ReportCards\ReportCardCumulativePolicy;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Term Calculation settings (Examinations > Term Calculation, user
 * request 2026-07-24): "for third term - the school can decide to add
 * first term total and second term total plus the third term total then
 * find the average for each subject offered by the class - admin can
 * toggle it off if the school decide otherwise... alternatively, the
 * school can decide to add the total from previous terms but it wont
 * find the average, it just display it for comparison purpose only."
 * One choice per term SEQUENCE (not per individual term row), since the
 * same "how should term 2 treat term 1" question applies the same way
 * across every session, not once per session. Admin only, matching
 * Narrative Evaluation's own tier.
 */
class ReportCardCalculationController extends Controller
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

    /**
     * One row per distinct term sequence found across every session (not
     * per individual term), labelled from whichever term at that
     * sequence is in the current session, falling back to any term at
     * that sequence if the current session does not go that far.
     */
    protected function sequenceLabels(): \Illuminate\Support\Collection
    {
        $terms = Term::with('session')->get();
        $currentSessionId = $terms->first(fn (Term $t) => $t->session?->is_current)?->academic_session_id;

        return $terms->pluck('sequence')->unique()->sort()->values()->mapWithKeys(function ($sequence) use ($terms, $currentSessionId) {
            $example = $terms->first(fn (Term $t) => $t->sequence === $sequence && $t->academic_session_id === $currentSessionId)
                ?? $terms->first(fn (Term $t) => $t->sequence === $sequence);

            return [$sequence => $example?->name ?? "Term {$sequence}"];
        });
    }

    public function index(Request $request, ReportCardCumulativePolicy $policy): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.approve_results');
        $school = $this->school($request);

        return view('school.examinations.report-card-calculation', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'sequenceLabels' => $this->sequenceLabels(),
            'policy' => $policy->all(),
            'modes' => config('skulos.report_card_cumulative_modes', []),
            'defaultMode' => config('skulos.default_report_card_cumulative_mode', ReportCardCumulativePolicy::MODE_AVERAGE),
        ]);
    }

    public function update(Request $request, ReportCardCumulativePolicy $policy): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.approve_results');

        $validModes = array_keys(config('skulos.report_card_cumulative_modes', []));
        $data = $request->validate([
            'sequence' => ['array'],
            'sequence.*' => [Rule::in($validModes)],
        ]);

        $policy->setPolicy($data['sequence'] ?? []);

        return back()->with('status', 'Term Calculation settings saved.');
    }
}
