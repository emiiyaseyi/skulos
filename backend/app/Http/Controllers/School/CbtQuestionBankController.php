<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CbtQuestion;
use App\Models\Tenant\CbtQuestionAnswer;
use App\Models\Tenant\CbtQuestionCategory;
use App\Services\Cbt\QuestionAnswerWriter;
use App\Services\Cbt\QuestionPortabilityService;
use App\Services\Media\ImageProcessingService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The school's own question bank — build questions once, reuse them
 * across as many assessments as needed. Shaped after the reference
 * repo's own questionbank.php: a category sidebar with live counts, a
 * site-wide search, and bulk delete / move-to-category / change-mark
 * actions, plus import and export.
 *
 * Answer options are real cbt_question_answers rows (written by
 * QuestionAnswerWriter), never a JSON blob — that is what makes partial
 * credit and per-option feedback possible at all.
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
        // students/parents (to see/take their OWN assigned assessments)
        // — this admin screen shows every question's correct answer, so
        // it must gate on the higher tier that only staff building
        // content actually has.
        $this->authorizeAction($request, 'assessment_hub.create');
        $school = $this->school($request);

        $categoryId = $request->query('cat');
        $search = trim((string) $request->query('search', ''));

        $questions = CbtQuestion::with(['category', 'answers'])
            ->when($categoryId, fn ($q) => $q->where('category_id', $categoryId))
            ->when($search !== '', fn ($q) => $q->where(fn ($inner) => $inner
                ->where('prompt', 'like', "%{$search}%")
                ->orWhere('name', 'like', "%{$search}%")))
            ->orderByDesc('id')->paginate(30)->withQueryString();

        return view('school.cbt.questions.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'questions' => $questions,
            'types' => CbtQuestion::TYPES,
            'categories' => CbtQuestionCategory::withCount('questions')->orderBy('sort_order')->orderBy('name')->get(),
            'activeCategoryId' => $categoryId,
            'search' => $search,
            'totalCount' => CbtQuestion::count(),
        ]);
    }

    public function store(Request $request, ImageProcessingService $images, QuestionAnswerWriter $answers): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.create');
        $school = $this->school($request);

        $data = $this->validatedQuestion($request);

        if ($request->hasFile('image')) {
            $data['image_path'] = $images->toWebp($request->file('image'), "cbt/{$school->slug}/questions");
        }

        $question = CbtQuestion::create($data);
        $answers->write($question, $request->all());

        $this->assertHasOptions($question);

        return back()->with('status', 'Question added.');
    }

    public function update(Request $request, ImageProcessingService $images, QuestionAnswerWriter $answers): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.update');
        $school = $this->school($request);
        $question = CbtQuestion::findOrFail($request->route('questionId'));

        $data = $this->validatedQuestion($request);

        if ($request->hasFile('image')) {
            $data['image_path'] = $images->toWebp($request->file('image'), "cbt/{$school->slug}/questions");
        }
        $data['is_active'] = $request->boolean('is_active');

        $question->update($data);
        $answers->write($question, $request->all());

        $this->assertHasOptions($question->fresh('answers'));

        return back()->with('status', 'Question updated.');
    }

    protected function validatedQuestion(Request $request): array
    {
        $data = $request->validate([
            'type' => ['required', Rule::in(CbtQuestion::TYPES)],
            'name' => ['nullable', 'string', 'max:255'],
            'prompt' => ['required', 'string', 'max:5000'],
            'points' => ['required', 'numeric', 'min:0.5'],
            'category_id' => ['nullable', 'exists:tenant.cbt_question_categories,id'],
            'general_feedback' => ['nullable', 'string', 'max:2000'],
            'marking_guidance' => ['nullable', 'string', 'max:2000'],
            'image' => ['nullable', 'file', 'image', 'max:2048'],
        ]);

        $data['shuffle_answers'] = $request->boolean('shuffle_answers');
        $data['case_sensitive'] = $request->boolean('case_sensitive');
        $data['is_mandatory'] = $request->boolean('is_mandatory');

        return $data;
    }

    /** Every type except essay needs at least one real answer row to be gradable at all. */
    protected function assertHasOptions(CbtQuestion $question): void
    {
        if ($question->type === 'essay') {
            return;
        }
        abort_if($question->answers()->count() === 0, 422, 'Add at least one answer option.');
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
            'action' => ['required', 'in:activate,deactivate,delete,move,change_mark'],
            'target_category_id' => ['nullable', 'exists:tenant.cbt_question_categories,id'],
            'new_mark' => ['nullable', 'numeric', 'min:0.5'],
        ]);

        $scope = CbtQuestion::whereIn('id', $data['question_ids']);

        if ($data['action'] === 'delete') {
            $count = $scope->count();
            $scope->delete();

            return back()->with('status', "{$count} question(s) removed.");
        }

        if ($data['action'] === 'move') {
            $count = $scope->update(['category_id' => $data['target_category_id'] ?? null]);

            return back()->with('status', "{$count} question(s) moved.");
        }

        if ($data['action'] === 'change_mark') {
            abort_if(! isset($data['new_mark']), 422, 'Enter the new mark first.');
            $count = $scope->update(['points' => $data['new_mark']]);

            return back()->with('status', "{$count} question(s) re-marked.");
        }

        $count = $scope->update(['is_active' => $data['action'] === 'activate']);

        return back()->with('status', "{$count} question(s) updated.");
    }

    public function export(Request $request, QuestionPortabilityService $portability): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'assessment_hub.export');
        $format = (string) $request->route('format');
        abort_unless(in_array($format, ['csv', 'moodlexml', 'gift', 'json'], true), 404);

        $questions = CbtQuestion::with(['category', 'answers'])
            ->when($request->query('cat'), fn ($q) => $q->where('category_id', $request->query('cat')))
            ->orderBy('id')->get();

        return response($portability->export($questions, $format), 200, [
            'Content-Type' => $portability->mimeFor($format),
            'Content-Disposition' => 'attachment; filename="'.$portability->filenameFor($format).'"',
        ]);
    }

    /** The dedicated import screen — formats, per-format instructions, and sample template downloads. */
    public function importForm(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'assessment_hub.create');
        $school = $this->school($request);

        return view('school.cbt.questions.import', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'formats' => QuestionPortabilityService::IMPORT_FORMATS,
            'placeholders' => QuestionPortabilityService::PASTE_PLACEHOLDERS,
            'categories' => CbtQuestionCategory::orderBy('sort_order')->orderBy('name')->get(),
            'preview' => session('import_preview'),
        ]);
    }

    /** Downloads a filled-in sample file for the chosen format, so an admin never has to guess the layout. */
    public function template(Request $request, QuestionPortabilityService $portability): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'assessment_hub.create');
        $format = (string) $request->route('format');
        abort_unless(array_key_exists($format, QuestionPortabilityService::IMPORT_FORMATS), 404);

        $template = $portability->template($format);

        return response($template['content'], 200, [
            'Content-Type' => $template['mime'],
            'Content-Disposition' => 'attachment; filename="'.$template['filename'].'"',
        ]);
    }

    public function import(Request $request, QuestionPortabilityService $portability): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.create');
        $school = $this->school($request);

        $data = $request->validate([
            'format' => ['required', Rule::in(array_keys(QuestionPortabilityService::IMPORT_FORMATS))],
            // Either an uploaded file OR pasted text — neither is
            // required on its own, but one of them must be present
            // (checked below, since "required_without" cannot express
            // "one of these two" cleanly for a file input).
            'file' => ['nullable', 'file', 'max:10240'],
            'pasted_text' => ['nullable', 'string', 'max:2000000'],
            'category_id' => ['nullable', 'exists:tenant.cbt_question_categories,id'],
            'dry_run' => ['sometimes', 'boolean'],
        ]);

        // An uploaded file wins over pasted text, matching the reference.
        $contents = $request->hasFile('file')
            ? (string) file_get_contents($request->file('file')->getRealPath())
            : trim((string) ($data['pasted_text'] ?? ''));

        if ($contents === '') {
            throw \Illuminate\Validation\ValidationException::withMessages([
                'file' => 'Nothing to import. Upload a file or paste your question text.',
            ]);
        }

        [$parsed, $errors] = $portability->parse($contents, $data['format']);

        // Dry run: parse and report what WOULD land, without writing a
        // single row. Cheap insurance against importing 500 mis-parsed
        // questions and having to unpick them by hand afterwards.
        if ($request->boolean('dry_run')) {
            return back()->with('import_preview', [
                'format' => $data['format'],
                'count' => count($parsed),
                'errors' => $errors,
                'sample' => array_map(fn ($row) => [
                    'type' => $row['type'],
                    'prompt' => \Illuminate\Support\Str::limit($row['prompt'], 90),
                    'points' => $row['points'],
                    'answers' => count($row['answers']),
                    'correct' => count(array_filter($row['answers'], fn ($a) => $a['fraction'] > 0)),
                ], array_slice($parsed, 0, 10)),
            ]);
        }

        $imported = 0;
        foreach ($parsed as $row) {
            // A category named in the file wins; otherwise everything
            // lands in whichever category the admin picked on the form.
            $categoryId = $data['category_id'] ?? null;
            if (! empty($row['category'])) {
                $categoryId = CbtQuestionCategory::firstOrCreate(
                    ['name' => $row['category']],
                    ['sort_order' => (CbtQuestionCategory::max('sort_order') ?? -1) + 1]
                )->id;
            }

            $question = CbtQuestion::create([
                'category_id' => $categoryId,
                'name' => $row['name'] ?? null,
                'type' => $row['type'],
                'prompt' => $row['prompt'],
                'points' => $row['points'],
                'case_sensitive' => (bool) ($row['case_sensitive'] ?? false),
                'general_feedback' => $row['general_feedback'] ?? null,
                'marking_guidance' => $row['marking_guidance'] ?? null,
                'is_active' => true,
            ]);

            foreach ($row['answers'] as $sortOrder => $answer) {
                CbtQuestionAnswer::create(array_merge($answer, [
                    'question_id' => $question->id,
                    'sort_order' => $sortOrder,
                ]));
            }
            $imported++;
        }

        $message = "{$imported} question(s) imported.";
        if ($errors !== []) {
            $message .= ' '.count($errors).' row(s) skipped: '.implode(' ', array_slice($errors, 0, 3));
        }

        return redirect()->route('school.cbt.questions.index', ['school' => $school->slug])->with('status', $message);
    }

    public function storeCategory(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.create');
        $data = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]);
        $data['sort_order'] = (CbtQuestionCategory::max('sort_order') ?? -1) + 1;

        CbtQuestionCategory::create($data);

        return back()->with('status', 'Category added.');
    }

    public function updateCategory(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.update');
        $category = CbtQuestionCategory::findOrFail($request->route('categoryId'));
        $category->update($request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
        ]));

        return back()->with('status', 'Category updated.');
    }

    public function destroyCategory(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'assessment_hub.delete');
        $category = CbtQuestionCategory::findOrFail($request->route('categoryId'));
        // Questions are never deleted with their category — they just
        // fall back to uncategorised (the FK is nullOnDelete).
        $category->delete();

        return back()->with('status', 'Category removed. Its questions are now uncategorised.');
    }
}
