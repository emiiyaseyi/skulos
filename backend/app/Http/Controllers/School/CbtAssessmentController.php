<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtAssessment;
use App\Models\Tenant\CbtAssessmentQuestion;
use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtSecurityProfile;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Subject;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Assembles a timed assessment from the question bank — the "Papers"
 * screen this was originally built as (a name invented here, with no
 * counterpart in the reference repo) is now named for what it actually
 * is. Reordering questions reuses the same move-up/down-by-swapping-
 * sequence pattern as AdmissionSettingsController::moveStage().
 *
 * Also where an assessment's Moodle-course-equivalent context is set
 * (subject/term/attached class arms) — see CbtAssessment::
 * eligibleTakerIds()'s own docblock for why this exists at all.
 */
class CbtAssessmentController extends Controller
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

    public function index(Request $request): \Illuminate\View\View
    {
        // 'create', not 'view': see CbtQuestionBankController::index()'s
        // own comment — assessment_hub.view is also granted to
        // students/parents to see/take their own assessments.
        $this->authorizeAction($request, 'assessment_hub.create');
        $school = $this->school($request);
        CbtSecurityProfile::ensureSeeded();

        return view('school.cbt.assessments.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'assessments' => CbtAssessment::with(['securityProfile', 'subject'])->withCount('assessmentQuestions')->orderByDesc('id')->paginate(30)->withQueryString(),
            'securityProfiles' => CbtSecurityProfile::orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.create');
        $school = $this->school($request);

        $data = $this->validatedAssessment($request);
        $assessment = CbtAssessment::create($data);

        return redirect()->route('school.cbt.assessments.edit', ['school' => $school->slug, 'assessmentId' => $assessment->id])
            ->with('status', 'Assessment created. Add questions below.');
    }

    protected function validatedAssessment(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'subject_id' => ['nullable', 'exists:tenant.subjects,id'],
            'term_id' => ['nullable', 'exists:tenant.terms,id'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'security_profile_id' => ['required', 'exists:tenant.cbt_security_profiles,id'],
            'audience' => ['required', Rule::in([CbtAssessment::AUDIENCE_STUDENT, CbtAssessment::AUDIENCE_STAFF])],
            'requires_double_grading' => ['sometimes', 'boolean'],
        ]);
        $data['requires_double_grading'] = $request->boolean('requires_double_grading');

        return $data;
    }

    public function edit(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assessment_hub.create');
        $school = $this->school($request);
        $assessment = CbtAssessment::with(['assessmentQuestions.question', 'securityProfile', 'classArms'])->findOrFail($request->route('assessmentId'));

        return view('school.cbt.assessments.edit', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'assessment' => $assessment,
            'securityProfiles' => CbtSecurityProfile::orderBy('id')->get(),
            'subjects' => Subject::orderBy('name')->get(),
            'terms' => Term::orderBy('id')->get(),
            'classArms' => ClassArm::with(['schoolClass', 'arm'])->get(),
            'bankQuestions' => CbtQuestion::where('is_active', true)
                ->whereNotIn('id', $assessment->assessmentQuestions->pluck('question_id'))
                ->orderByDesc('id')->get(),
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.update');
        $school = $this->school($request);
        $assessment = CbtAssessment::findOrFail($request->route('assessmentId'));

        $data = $this->validatedAssessment($request);
        $data['is_active'] = $request->boolean('is_active');
        $assessment->update($data);

        $assessment->classArms()->sync($request->input('class_arm_ids', []));

        return redirect()->route('school.cbt.assessments.edit', ['school' => $school->slug, 'assessmentId' => $assessment->id])
            ->with('status', 'Assessment updated.');
    }

    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.delete');
        $school = $this->school($request);
        $assessment = CbtAssessment::findOrFail($request->route('assessmentId'));
        $assessment->delete();

        return redirect()->route('school.cbt.assessments.index', ['school' => $school->slug])->with('status', 'Assessment removed.');
    }

    public function addQuestion(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.update');
        $assessment = CbtAssessment::findOrFail($request->route('assessmentId'));

        $data = $request->validate(['question_id' => ['required', 'exists:tenant.cbt_questions,id']]);

        CbtAssessmentQuestion::firstOrCreate(
            ['assessment_id' => $assessment->id, 'question_id' => $data['question_id']],
            ['sequence' => (CbtAssessmentQuestion::where('assessment_id', $assessment->id)->max('sequence') ?? -1) + 1]
        );

        return back()->with('status', 'Question added to assessment.');
    }

    public function removeQuestion(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.update');
        $assessmentQuestion = CbtAssessmentQuestion::findOrFail($request->route('assessmentQuestionId'));
        $assessmentId = $assessmentQuestion->assessment_id;
        $removedSequence = $assessmentQuestion->sequence;
        $assessmentQuestion->delete();
        CbtAssessmentQuestion::where('assessment_id', $assessmentId)->where('sequence', '>', $removedSequence)->decrement('sequence');

        return back()->with('status', 'Question removed from assessment.');
    }

    public function moveQuestion(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.update');
        $assessmentQuestion = CbtAssessmentQuestion::findOrFail($request->route('assessmentQuestionId'));
        $direction = $request->route('direction');
        abort_unless(in_array($direction, ['up', 'down'], true), 404);

        $swapSequence = $direction === 'up' ? $assessmentQuestion->sequence - 1 : $assessmentQuestion->sequence + 1;
        $swapWith = CbtAssessmentQuestion::where('assessment_id', $assessmentQuestion->assessment_id)->where('sequence', $swapSequence)->first();

        if ($swapWith) {
            $swapWith->update(['sequence' => $assessmentQuestion->sequence]);
            $assessmentQuestion->update(['sequence' => $swapSequence]);
        }

        return back()->with('status', 'Question order updated.');
    }
}
