<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use App\Services\Results\ResultService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Academic Insights (Advance plan feature, built 2026-07-18): a whole
 * school, subject-level view distinct from the Student Performance
 * Dashboard (which is class/student level, current term only). Every
 * plan feature's own screen picks a term the same way Term Broadsheets
 * does (user directive 2026-07-18: "make it possible that the admin can
 * go back to another session and term"), not locked to the current one.
 * School wide by design, gated behind examinations.approve_results (the
 * same "blanket" permission tier used elsewhere for policy level
 * screens), a scoped class/subject teacher's own reduced view would not
 * mean much for a subject-wide-across-the-school ranking.
 */
class AcademicInsightsController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    public function index(Request $request, ResultService $results): \Illuminate\View\View
    {
        $school = $this->school($request);
        abort_unless(app(Rbac::class)->check(Auth::user(), $school, 'examinations.approve_results'), 403, 'You do not have permission for this.');

        $terms = Term::with('session')->orderByDesc('starts_on')->get();
        $term = $terms->firstWhere('id', (int) $request->query('term_id')) ?? $terms->firstWhere('is_current', true) ?? $terms->first();

        $subjectAverages = $term ? $results->subjectAveragesForTerm($term->id) : collect();
        $gradeDistribution = $term ? $results->gradeDistributionForTerm($term->id, GradingScale::default()) : [];

        return view('school.examinations.academic-insights', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'terms' => $terms,
            'term' => $term,
            'subjectAverages' => $subjectAverages,
            'gradeDistribution' => $gradeDistribution,
            'gradeTotal' => array_sum($gradeDistribution),
        ]);
    }
}
