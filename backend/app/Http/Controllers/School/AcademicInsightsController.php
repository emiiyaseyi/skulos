<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\Section;
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

        // Scope to one section or one class within it (user request
        // 2026-07-19: "should also have another section to view per
        // section or a class only under the term and session"). A class
        // filter without a matching section filter is ignored, the
        // section picker drives which classes are even offered.
        $sections = Section::orderBy('sequence')->get();
        $sectionId = $request->integer('section_id') ?: null;
        $section = $sectionId ? $sections->firstWhere('id', $sectionId) : null;

        $classArms = collect();
        $classArmId = null;
        $classArm = null;
        if ($section) {
            $classArms = ClassArm::with('arm')->whereHas('schoolClass', fn ($q) => $q->where('section_id', $section->id))
                ->get()->sortBy(fn (ClassArm $a) => $a->orderKey())->values();
            $classArmId = $request->integer('class_arm_id') ?: null;
            $classArm = $classArmId ? $classArms->firstWhere('id', $classArmId) : null;
        }

        $classArmIds = null;
        if ($classArm) {
            $classArmIds = [$classArm->id];
        } elseif ($section) {
            $classArmIds = ClassArm::whereHas('schoolClass', fn ($q) => $q->where('section_id', $section->id))->pluck('id')->all();
        }

        $subjectAverages = $term ? $results->subjectAveragesForTerm($term->id, $classArmIds) : collect();
        $gradeDistribution = $term ? $results->gradeDistributionForTerm($term->id, GradingScale::default(), $classArmIds) : [];

        return view('school.examinations.academic-insights', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'terms' => $terms,
            'term' => $term,
            'sections' => $sections,
            'section' => $section,
            'classArms' => $classArms,
            'classArm' => $classArm,
            'subjectAverages' => $subjectAverages,
            'gradeDistribution' => $gradeDistribution,
            'gradeTotal' => array_sum($gradeDistribution),
        ]);
    }
}
