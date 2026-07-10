<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Score;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use App\Services\Results\ResultService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Results entry (user request 2026-07-09, built 2026-07-10): a teacher
 * picks a term, class, and subject they teach, then enters every
 * student's score for each of the school's assessment components in one
 * table. Report cards/broadsheets already compute everything from `Score`
 * rows via ResultService, so this screen only ever needs to write
 * Score::updateOrCreate rows in the right shape, nothing else changes.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class ExaminationController extends Controller
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
     * Roles with a blanket examinations grant (owner/admin/principal,
     * vice-principal, examination-officer) also get "approve_results",
     * which teacher/class-teacher/subject-teacher never do (see
     * config/skulos.php roles). Used as a proxy for "can enter scores for
     * any class/subject" vs "only what I actually teach".
     */
    protected function hasBlanketAccess(Request $request): bool
    {
        return app(Rbac::class)->check(Auth::user(), $this->school($request), 'examinations.approve_results');
    }

    /**
     * The (class arm, subject) pairs this user may enter scores for:
     * everything the school offers for a blanket role, or only what a
     * TeachingAssignment says this teacher actually teaches.
     */
    protected function allowedPairs(Request $request): \Illuminate\Support\Collection
    {
        if ($this->hasBlanketAccess($request)) {
            $pairs = collect();
            ClassArm::with(['schoolClass.subjects', 'arm'])->get()->each(function (ClassArm $classArm) use (&$pairs) {
                foreach ($classArm->schoolClass?->subjects ?? [] as $subject) {
                    $pairs->push([
                        'class_arm_id' => (int) $classArm->id,
                        'subject_id' => (int) $subject->id,
                        'label' => $classArm->label().': '.$subject->name,
                    ]);
                }
            });

            return $pairs->sortBy('label')->values();
        }

        $staff = Staff::where('user_id', Auth::id())->first();
        if (! $staff) {
            return collect();
        }

        return TeachingAssignment::where('staff_id', $staff->id)
            ->with(['subject', 'classArm.schoolClass', 'classArm.arm'])
            ->get()
            ->map(fn (TeachingAssignment $a) => [
                'class_arm_id' => (int) $a->class_arm_id,
                'subject_id' => (int) $a->subject_id,
                'label' => $a->classArm->label().': '.$a->subject->name,
            ])
            ->unique(fn ($p) => $p['class_arm_id'].':'.$p['subject_id'])
            ->sortBy('label')
            ->values();
    }

    public function index(Request $request, ResultService $results): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.view');
        $school = $this->school($request);

        $pairs = $this->allowedPairs($request);
        $terms = Term::with('session')->orderByDesc('starts_on')->get();

        $termId = (int) $request->query('term_id', Term::current()->value('id'));
        [$classArmId, $subjectId] = array_pad(explode(':', (string) $request->query('pair', '')), 2, 0);
        $classArmId = (int) $classArmId;
        $subjectId = (int) $subjectId;

        $selectedPair = $pairs->first(fn ($p) => $p['class_arm_id'] === $classArmId && $p['subject_id'] === $subjectId);

        $students = collect();
        $components = collect();
        $existingScores = collect();
        $summaries = [];

        if ($selectedPair && $termId) {
            $students = Student::active()
                ->where('current_class_arm_id', $classArmId)
                ->orderBy('first_name')
                ->get();
            $components = AssessmentComponent::orderBy('sequence')->get();
            $existingScores = Score::where('class_arm_id', $classArmId)
                ->where('subject_id', $subjectId)
                ->where('term_id', $termId)
                ->get()
                ->groupBy('student_id');

            foreach ($students as $student) {
                $summaries[$student->id] = $results->subjectResult($student->id, $subjectId, $termId);
            }
        }

        return view('school.examinations.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'pairs' => $pairs,
            'terms' => $terms,
            'termId' => $termId,
            'classArmId' => $classArmId,
            'subjectId' => $subjectId,
            'selectedPair' => $selectedPair,
            'students' => $students,
            'components' => $components,
            'existingScores' => $existingScores,
            'summaries' => $summaries,
            'canSave' => app(Rbac::class)->check(Auth::user(), $school, 'examinations.create'),
        ]);
    }

    public function save(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.create');
        $school = $this->school($request);

        $data = $request->validate([
            'term_id' => ['required', 'integer'],
            'class_arm_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'scores' => ['array'],
        ]);

        $pairs = $this->allowedPairs($request);
        $allowed = $pairs->first(fn ($p) => $p['class_arm_id'] === (int) $data['class_arm_id'] && $p['subject_id'] === (int) $data['subject_id']);
        abort_unless($allowed, 403, 'You do not teach this class and subject.');

        $term = Term::find($data['term_id']);
        abort_unless($term, 404);

        $components = AssessmentComponent::orderBy('sequence')->get()->keyBy('id');
        $studentIds = Student::active()->where('current_class_arm_id', $data['class_arm_id'])->pluck('id')->all();

        $toSave = [];
        $errors = [];

        foreach ($request->input('scores', []) as $studentId => $componentScores) {
            if (! in_array((int) $studentId, $studentIds, true)) {
                continue;
            }
            foreach ($componentScores as $componentId => $value) {
                if ($value === null || $value === '') {
                    continue;
                }
                $component = $components->get((int) $componentId);
                if (! $component) {
                    continue;
                }
                if (! is_numeric($value) || $value < 0 || $value > (float) $component->max_score) {
                    $errors[] = "A score for {$component->name} must be between 0 and {$component->max_score}.";
                    continue;
                }
                $toSave[] = [
                    'student_id' => (int) $studentId,
                    'assessment_component_id' => (int) $componentId,
                    'score' => (float) $value,
                ];
            }
        }

        if ($errors) {
            return back()->withInput()->with('error', 'Nothing was saved. '.array_unique($errors)[0].(count(array_unique($errors)) > 1 ? ' ('.(count(array_unique($errors)) - 1).' more)' : ''));
        }

        foreach ($toSave as $row) {
            Score::updateOrCreate(
                [
                    'student_id' => $row['student_id'],
                    'subject_id' => (int) $data['subject_id'],
                    'term_id' => (int) $data['term_id'],
                    'assessment_component_id' => $row['assessment_component_id'],
                ],
                [
                    'class_arm_id' => (int) $data['class_arm_id'],
                    'score' => $row['score'],
                ]
            );
        }

        return redirect()->route('school.examinations.index', [
            'school' => $school->slug,
            'term_id' => $data['term_id'],
            'pair' => $data['class_arm_id'].':'.$data['subject_id'],
        ])->with('status', count($toSave).' score'.(count($toSave) === 1 ? '' : 's').' saved.');
    }
}
