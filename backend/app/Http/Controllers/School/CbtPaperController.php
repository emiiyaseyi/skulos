<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtPaper;
use App\Models\Tenant\CbtPaperQuestion;
use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtSecurityProfile;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * SKULOS Assessment Hub, Phase 2a: assembles a timed paper from the
 * question bank. Reordering questions on a paper reuses the same
 * move-up/down-by-swapping-sequence pattern as AdmissionSettings
 * Controller::moveStage() (built for Admissions this same session).
 */
class CbtPaperController extends Controller
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

        return view('school.cbt.papers.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'papers' => CbtPaper::with('securityProfile')->withCount('paperQuestions')->orderByDesc('id')->paginate(30)->withQueryString(),
            'securityProfiles' => CbtSecurityProfile::orderBy('id')->get(),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.create');
        $school = $this->school($request);

        $data = $this->validatedPaper($request);
        $paper = CbtPaper::create($data);

        return redirect()->route('school.cbt.papers.edit', ['school' => $school->slug, 'paperId' => $paper->id])
            ->with('status', 'Paper created. Add questions below.');
    }

    protected function validatedPaper(Request $request): array
    {
        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'instructions' => ['nullable', 'string', 'max:2000'],
            'duration_minutes' => ['required', 'integer', 'min:1', 'max:600'],
            'security_profile_id' => ['required', 'exists:tenant.cbt_security_profiles,id'],
            'audience' => ['required', Rule::in([CbtPaper::AUDIENCE_STUDENT, CbtPaper::AUDIENCE_STAFF])],
            'requires_double_grading' => ['sometimes', 'boolean'],
        ]);
        $data['requires_double_grading'] = $request->boolean('requires_double_grading');

        return $data;
    }

    public function edit(Request $request): \Illuminate\View\View
    {
        // 'create', not 'view': see CbtQuestionBankController::index()'s
        // own comment — assessment_hub.view is also granted to
        // students/parents to see/take their own assessments.
        $this->authorizeAction($request, 'assessment_hub.create');
        $school = $this->school($request);
        $paper = CbtPaper::with(['paperQuestions.question', 'securityProfile'])->findOrFail($request->route('paperId'));

        return view('school.cbt.papers.edit', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'paper' => $paper,
            'securityProfiles' => CbtSecurityProfile::orderBy('id')->get(),
            'bankQuestions' => CbtQuestion::where('is_active', true)
                ->whereNotIn('id', $paper->paperQuestions->pluck('question_id'))
                ->orderByDesc('id')->get(),
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.update');
        $school = $this->school($request);
        $paper = CbtPaper::findOrFail($request->route('paperId'));

        $data = $this->validatedPaper($request);
        $data['is_active'] = $request->boolean('is_active');
        $paper->update($data);

        return redirect()->route('school.cbt.papers.edit', ['school' => $school->slug, 'paperId' => $paper->id])
            ->with('status', 'Paper updated.');
    }

    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.delete');
        $school = $this->school($request);
        $paper = CbtPaper::findOrFail($request->route('paperId'));
        $paper->delete();

        return redirect()->route('school.cbt.papers.index', ['school' => $school->slug])->with('status', 'Paper removed.');
    }

    public function addQuestion(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.update');
        $paper = CbtPaper::findOrFail($request->route('paperId'));

        $data = $request->validate(['question_id' => ['required', 'exists:tenant.cbt_questions,id']]);

        CbtPaperQuestion::firstOrCreate(
            ['paper_id' => $paper->id, 'question_id' => $data['question_id']],
            ['sequence' => (CbtPaperQuestion::where('paper_id', $paper->id)->max('sequence') ?? -1) + 1]
        );

        return back()->with('status', 'Question added to paper.');
    }

    public function removeQuestion(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.update');
        $paperQuestion = CbtPaperQuestion::findOrFail($request->route('paperQuestionId'));
        $paperId = $paperQuestion->paper_id;
        $removedSequence = $paperQuestion->sequence;
        $paperQuestion->delete();
        CbtPaperQuestion::where('paper_id', $paperId)->where('sequence', '>', $removedSequence)->decrement('sequence');

        return back()->with('status', 'Question removed from paper.');
    }

    public function moveQuestion(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.update');
        $paperQuestion = CbtPaperQuestion::findOrFail($request->route('paperQuestionId'));
        $direction = $request->route('direction');
        abort_unless(in_array($direction, ['up', 'down'], true), 404);

        $swapSequence = $direction === 'up' ? $paperQuestion->sequence - 1 : $paperQuestion->sequence + 1;
        $swapWith = CbtPaperQuestion::where('paper_id', $paperQuestion->paper_id)->where('sequence', $swapSequence)->first();

        if ($swapWith) {
            $swapWith->update(['sequence' => $paperQuestion->sequence]);
            $paperQuestion->update(['sequence' => $swapSequence]);
        }

        return back()->with('status', 'Question order updated.');
    }
}
