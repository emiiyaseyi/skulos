<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtQuestion;
use App\Services\Media\ImageProcessingService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * SKULOS Assessment Hub, Phase 2a: the school's own question bank —
 * build questions once, reuse them across as many papers as needed.
 * Same CRUD + bulk-action shape as AdmissionFormBuilderController.
 */
class CbtQuestionBankController extends Controller
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
        // 'create', not 'view': assessment_hub.view is also granted to
        // students/parents (Phase 2b, to see/take their OWN assigned
        // assessments) — this admin screen shows every question's
        // correct answer, so it must gate on the higher tier that only
        // staff building content actually has.
        $this->authorizeAction($request, 'assessment_hub.create');
        $school = $this->school($request);

        return view('school.cbt.questions.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'questions' => CbtQuestion::orderByDesc('id')->paginate(30)->withQueryString(),
            'types' => CbtQuestion::TYPES,
        ]);
    }

    /**
     * Parses the type-specific form fields into the options/correct_answer
     * JSON shapes documented on CbtQuestion's own docblock.
     */
    protected function buildAnswerShape(Request $request, string $type): array
    {
        return match ($type) {
            'multiple_choice_single', 'multiple_choice_multiple' => [
                'options' => $this->linesToArray($request->input('mc_options')),
                'correct_answer' => $type === 'multiple_choice_single'
                    ? $request->input('mc_correct_single')
                    : $this->linesToArray($request->input('mc_correct_multiple_raw')),
            ],
            'true_false' => [
                'options' => null,
                'correct_answer' => $request->boolean('true_false_answer'),
            ],
            'short_answer' => [
                'options' => null,
                'correct_answer' => $request->input('short_answer_correct'),
            ],
            'matching' => [
                'options' => [
                    'left' => $this->linesToArray($request->input('matching_left')),
                    'right' => $this->linesToArray($request->input('matching_right')),
                ],
                'correct_answer' => $this->parsePairs($request->input('matching_pairs')),
            ],
            default => ['options' => null, 'correct_answer' => null], // essay
        };
    }

    protected function linesToArray(?string $raw): array
    {
        if (! $raw) {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode("\n", $raw)), fn ($line) => $line !== ''));
    }

    /** "0=1" per line -> {"0":"1"} (left-index => right-index), matching CbtQuestion's own documented matching shape. */
    protected function parsePairs(?string $raw): array
    {
        $pairs = [];
        foreach ($this->linesToArray($raw) as $line) {
            [$left, $right] = array_pad(explode('=', $line, 2), 2, null);
            if ($left !== null && $right !== null) {
                $pairs[trim($left)] = trim($right);
            }
        }

        return $pairs;
    }

    public function store(Request $request, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.create');
        $school = $this->school($request);

        $data = $request->validate([
            'type' => ['required', Rule::in(CbtQuestion::TYPES)],
            'prompt' => ['required', 'string', 'max:5000'],
            'points' => ['required', 'numeric', 'min:0.5'],
            'category' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'file', 'image', 'max:2048'],
        ]);

        $imagePath = $request->hasFile('image')
            ? $images->toWebp($request->file('image'), "cbt/{$school->slug}/questions")
            : null;

        $question = CbtQuestion::create(array_merge($data, [
            'image_path' => $imagePath,
        ], $this->buildAnswerShape($request, $data['type'])));

        if ($question->type === 'multiple_choice_single' || $question->type === 'multiple_choice_multiple' || $question->type === 'matching') {
            abort_if(empty($question->options), 422, 'List at least one option.');
        }

        return back()->with('status', 'Question added.');
    }

    public function update(Request $request, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.update');
        $school = $this->school($request);
        $question = CbtQuestion::findOrFail($request->route('questionId'));

        $data = $request->validate([
            'type' => ['required', Rule::in(CbtQuestion::TYPES)],
            'prompt' => ['required', 'string', 'max:5000'],
            'points' => ['required', 'numeric', 'min:0.5'],
            'category' => ['nullable', 'string', 'max:100'],
            'image' => ['nullable', 'file', 'image', 'max:2048'],
            'is_active' => ['sometimes', 'boolean'],
        ]);

        if ($request->hasFile('image')) {
            $data['image_path'] = $images->toWebp($request->file('image'), "cbt/{$school->slug}/questions");
        }
        $data['is_active'] = $request->boolean('is_active');

        $question->update(array_merge($data, $this->buildAnswerShape($request, $data['type'])));

        return back()->with('status', 'Question updated.');
    }

    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.delete');
        $question = CbtQuestion::findOrFail($request->route('questionId'));
        $question->delete();

        return back()->with('status', 'Question removed.');
    }

    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.bulk');
        $data = $request->validate([
            'question_ids' => ['required', 'array', 'min:1'],
            'question_ids.*' => ['exists:tenant.cbt_questions,id'],
            'action' => ['required', 'in:activate,deactivate,delete'],
        ]);

        if ($data['action'] === 'delete') {
            $count = CbtQuestion::whereIn('id', $data['question_ids'])->count();
            CbtQuestion::whereIn('id', $data['question_ids'])->delete();

            return back()->with('status', "{$count} question(s) removed.");
        }

        $count = CbtQuestion::whereIn('id', $data['question_ids'])->update(['is_active' => $data['action'] === 'activate']);

        return back()->with('status', "{$count} question(s) updated.");
    }
}
