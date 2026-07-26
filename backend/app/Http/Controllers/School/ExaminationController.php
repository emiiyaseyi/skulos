<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\AssessmentComponent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Comment;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Score;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Subject;
use App\Models\Tenant\SubjectRemark;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Services\Bulk\CsvReader;
use App\Services\Rbac\Rbac;
use App\Services\Rbac\TeacherScope;
use App\Services\Results\AssessmentWindowService;
use App\Services\Results\CommentBank;
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
    /**
     * Sorted by the school's real class order via ClassArm::orderKey()
     * (section sequence, then class sequence), not the label string
     * alphabetically (found 2026-07-11: an alphabetical sort put "JSS 3"
     * before "Nursery 1" before "Primary 1", not the pedagogical order
     * the school actually uses).
     */
    protected function allowedPairs(Request $request, int $termId): \Illuminate\Support\Collection
    {
        if ($this->hasBlanketAccess($request)) {
            $pairs = collect();
            ClassArm::with(['schoolClass.section', 'schoolClass.subjects', 'schoolClass.narrativeTerms', 'subjects', 'arm'])->get()->each(function (ClassArm $classArm) use (&$pairs, $termId) {
                // Narrative Evaluation (2026-07-18, per-term 2026-07-26):
                // scores are not entered for a class toggled narrative for
                // THIS term, see NarrativeEvaluationController; that
                // class's report card shows written comments instead
                // (ReportCardController::classEntry()), entered from
                // Report Cards, not here.
                if ($classArm->schoolClass?->isNarrativeEvaluationForTerm($termId)) {
                    return;
                }
                $order = $classArm->orderKey();
                // offeredSubjects() (2026-07-23): a stream with its own
                // subject overrides (SSS's Arts/Science/Commercial arms)
                // only offers scoring pairs for its own list, not every
                // subject the whole class collectively teaches across all
                // its streams.
                foreach ($classArm->offeredSubjects() as $subject) {
                    $pairs->push([
                        'class_arm_id' => (int) $classArm->id,
                        'subject_id' => (int) $subject->id,
                        'label' => $classArm->label().': '.$subject->name,
                        'order' => $order.'-'.$subject->name,
                    ]);
                }
            });

            return $pairs->sortBy('order')->values();
        }

        $staff = Staff::where('user_id', Auth::id())->first();
        if (! $staff) {
            return collect();
        }

        return TeachingAssignment::where('staff_id', $staff->id)
            ->with(['subject', 'classArm.schoolClass.section', 'classArm.schoolClass.narrativeTerms', 'classArm.arm'])
            ->get()
            ->reject(fn (TeachingAssignment $a) => $a->classArm?->schoolClass?->isNarrativeEvaluationForTerm($termId))
            ->map(fn (TeachingAssignment $a) => [
                'class_arm_id' => (int) $a->class_arm_id,
                'subject_id' => (int) $a->subject_id,
                'label' => $a->classArm->label().': '.$a->subject->name,
                'order' => $a->classArm->orderKey().'-'.$a->subject->name,
            ])
            ->unique(fn ($p) => $p['class_arm_id'].':'.$p['subject_id'])
            ->sortBy('order')
            ->values();
    }

    /**
     * Student Performance Dashboard (user directive 2026-07-16, "ALL
     * THERE": build out the remaining Reports & Analytics "Soon" items,
     * covers the student_performance_dashboard catalog feature). Reuses
     * ResultService::resultTrend()/classAveragePercentage()/
     * studentsBelowAverageThreshold(), the exact same real computations
     * the home dashboard's own "Result performance"/"Performance at
     * risk" widgets already use, extracted there specifically so this
     * screen would not duplicate that logic, just show more of it: the
     * full at-risk list (not capped to a handful) and a per-class
     * comparison for the current term, mirroring the Attendance
     * Dashboard's own "by class" list. A scoped teacher role
     * (TeacherScope) sees their own class arm(s)' average and their own
     * students' at-risk list, never the school wide "by class"
     * comparison, the same restriction pattern Attendance Dashboard
     * already applies.
     */
    public function performanceDashboard(Request $request, ResultService $results, TeacherScope $teacherScope): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.view');
        $school = $this->school($request);
        $user = Auth::user();
        $armIds = $teacherScope->classArmIds($user, $school);

        $term = Term::where('is_current', true)->first();
        $trend = $results->resultTrend();

        $termAverage = $term
            ? ($armIds === null
                ? $results->termAveragePercentage($term->id)
                : $results->averagePercentageForClassArms($armIds->all(), $term->id))
            : null;

        $threshold = \App\Models\Tenant\Setting::grab('at_risk_thresholds', config('skulos.at_risk_thresholds', ['attendance_pct' => 75, 'score_pct' => 40]))['score_pct'];

        $students = Student::active()->with('currentClassArm.schoolClass')
            ->when($armIds !== null, fn ($q) => $q->whereIn('current_class_arm_id', $armIds))
            ->get();
        $atRisk = $term ? array_map(fn ($row) => [
            'name' => $row['name'],
            'class' => $row['class'],
            'reason' => "Average {$row['average']}%",
        ], $results->studentsBelowAverageThreshold($students, $term->id, $threshold)) : [];

        $classRates = collect();
        if ($armIds === null && $term) {
            foreach (ClassArm::with('schoolClass.section', 'arm')->get() as $arm) {
                $average = $results->classAveragePercentage($arm->id, $term->id);
                if ($average === null) {
                    continue;
                }
                $classRates->push(['label' => $arm->label(), 'average' => $average]);
            }
            $classRates = $classRates->sortByDesc('average')->values();
        }

        return view('school.examinations.performance-dashboard', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'term' => $term,
            'trend' => $trend,
            'termAverage' => $termAverage,
            'atRisk' => $atRisk,
            'threshold' => $threshold,
            'classRates' => $classRates,
            'studentsAssessed' => $term ? Score::where('term_id', $term->id)
                ->when($armIds !== null, fn ($q) => $q->whereIn('class_arm_id', $armIds))
                ->distinct('student_id')->count('student_id') : 0,
        ]);
    }

    /**
     * Narrative Evaluation classes are deliberately excluded from
     * allowedPairs() above (they have no scores to enter), which used to
     * mean they simply vanished from this screen with no explanation
     * (user report 2026-07-21: "this kind of class is not seen under the
     * enter score page so one can enter the remarks for each subject
     * under the Narrative Evaluation option"). Their comment entry
     * genuinely lives on a different screen (ReportCardController::
     * classEntry(), gated on report_cards.update, not examinations.*),
     * so rather than duplicating that screen here, this surfaces a direct
     * link to it for whichever narrative classes this viewer may enter,
     * scoped by TeacherScope the same way every other list on this page is.
     */
    protected function narrativeClasses(Request $request, int $termId): \Illuminate\Support\Collection
    {
        $school = $this->school($request);
        if (! app(Rbac::class)->check(Auth::user(), $school, 'report_cards.update')) {
            return collect();
        }

        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);

        // Per-class-per-term (2026-07-26): a class is narrative for THIS
        // term only if it has a ClassNarrativeTerm row for it, not a
        // permanent flag, see SchoolClass::isNarrativeEvaluationForTerm().
        return SchoolClass::active()
            ->with('section', 'arms', 'narrativeTerms')
            ->get()
            ->filter(fn (SchoolClass $c) => $c->isNarrativeEvaluationForTerm($termId))
            ->filter(fn (SchoolClass $c) => $scopedArmIds === null || $c->arms->pluck('id')->intersect($scopedArmIds)->isNotEmpty())
            ->sortBy(fn (SchoolClass $c) => sprintf('%05d-%05d', $c->section?->sequence ?? 0, $c->sequence ?? 0))
            ->values();
    }

    public function index(Request $request, ResultService $results, CommentBank $commentBank, AssessmentWindowService $windows): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'examinations.view');
        $school = $this->school($request);

        $terms = Term::with('session')->orderByDesc('starts_on')->get();
        $termId = (int) $request->query('term_id', Term::current()->value('id'));

        $pairs = $this->allowedPairs($request, $termId);
        $narrativeClasses = $this->narrativeClasses($request, $termId);
        [$classArmId, $subjectId] = array_pad(explode(':', (string) $request->query('pair', '')), 2, 0);
        $classArmId = (int) $classArmId;
        $subjectId = (int) $subjectId;

        $selectedPair = $pairs->first(fn ($p) => $p['class_arm_id'] === $classArmId && $p['subject_id'] === $subjectId);

        $students = collect();
        $components = collect();
        $existingScores = collect();
        $summaries = [];
        $remarks = collect();
        $suggestions = [];
        $componentOpen = [];

        // Admin toggle on the Report Settings page (user request
        // 2026-07-26: "on/off toggle for the score entry remark
        // everywhere it appears"). Off suppresses both the server-side
        // prefill below and the client side live re-suggestion further
        // down; a teacher still types their own remark either way.
        $suggestedRemarkEnabled = ReportCardController::suggestedRemarkEnabled();

        if ($selectedPair && $termId) {
            $students = Student::active()
                ->where('current_class_arm_id', $classArmId)
                ->orderBy('first_name')
                ->get();
            $components = AssessmentComponent::orderBy('sequence')->get();

            // Assessment score-entry windows (user request 2026-07-18): a
            // blanket-access role (the one that also bypasses allowedPairs()'
            // "which class/subject" restriction) is never window-restricted.
            $term = Term::find($termId);
            if ($term) {
                $blanket = $this->hasBlanketAccess($request);
                $staff = Staff::where('user_id', Auth::id())->first();
                foreach ($components as $component) {
                    $componentOpen[$component->id] = $blanket || $windows->isOpenFor($component, $term, $staff);
                }
            }

            $existingScores = Score::where('class_arm_id', $classArmId)
                ->where('subject_id', $subjectId)
                ->where('term_id', $termId)
                ->get()
                ->groupBy('student_id');
            $remarks = SubjectRemark::where('class_arm_id', $classArmId)
                ->where('subject_id', $subjectId)
                ->where('term_id', $termId)
                ->get()
                ->keyBy('student_id');

            foreach ($students as $student) {
                $summaries[$student->id] = $results->subjectResult($student->id, $subjectId, $termId);
                if ($suggestedRemarkEnabled && $summaries[$student->id]['total'] > 0 && ! $remarks->get($student->id)?->remark) {
                    $suggestions[$student->id] = $commentBank->suggestionFor($summaries[$student->id]['total']);
                }
            }
        }

        $subjectsInThisClass = $pairs->where('class_arm_id', $classArmId)->pluck('subject_id')->unique()->count();

        // Live total/grade/remark recalculation as a teacher types, and a
        // 5 second autosave, both purely client side (user request
        // 2026-07-25: "live total/grade calc, 5s autosave, auto-suggested
        // remark" on this screen). Ships the same bands the server itself
        // already uses (GradingScale::bandFor()/CommentBank::suggestionFor())
        // to the browser as plain JSON, rather than round tripping to the
        // server on every keystroke; the eventual save (autosave or the
        // manual button) still goes through save()'s own validation and
        // window/lock checks unchanged, this is purely a live preview.
        $gradingBands = (GradingScale::default()?->bands ?? collect())
            ->map(fn ($b) => ['min' => (float) $b->min_score, 'max' => (float) $b->max_score, 'grade' => $b->grade])
            ->values();

        // The suggested-remark toggle only ever gates the comment bank
        // half of this (both the server prefill above and this JSON feed
        // for the client side re-suggestion); the live grade calc above
        // is a separate, always-on feature and keeps using gradingBands
        // regardless of this setting.
        if ($suggestedRemarkEnabled) {
            $commentBank->ensureSeeded();
            $commentBands = Comment::where('is_active', true)->get()
                ->groupBy(fn (Comment $c) => $c->min_score.':'.$c->max_score)
                ->map(fn ($group) => ['min' => (float) $group->first()->min_score, 'max' => (float) $group->first()->max_score, 'texts' => $group->pluck('text')->values()])
                ->values();
        } else {
            $commentBands = collect();
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
            'remarks' => $remarks,
            'suggestions' => $suggestions,
            'subjectsInThisClass' => $subjectsInThisClass,
            'narrativeClasses' => $narrativeClasses,
            'canSave' => app(Rbac::class)->check(Auth::user(), $school, 'examinations.create'),
            'componentOpen' => $componentOpen,
            'gradingBands' => $gradingBands,
            'commentBands' => $commentBands,
        ]);
    }

    public function save(Request $request, AssessmentWindowService $windows): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.create');
        $school = $this->school($request);

        $data = $request->validate([
            'term_id' => ['required', 'integer'],
            'class_arm_id' => ['required', 'integer'],
            'subject_id' => ['required', 'integer'],
            'scores' => ['array'],
        ]);

        $pairs = $this->allowedPairs($request, (int) $data['term_id']);
        $allowed = $pairs->first(fn ($p) => $p['class_arm_id'] === (int) $data['class_arm_id'] && $p['subject_id'] === (int) $data['subject_id']);
        abort_unless($allowed, 403, 'You do not teach this class and subject.');

        $term = Term::find($data['term_id']);
        abort_unless($term, 404);

        $components = AssessmentComponent::orderBy('sequence')->get()->keyBy('id');
        $studentIds = Student::active()->where('current_class_arm_id', $data['class_arm_id'])->pluck('id')->all();

        // A report card past the approved stage is locked for editing (ReportCardReview::LOCKED_FOR_EDITING);
        // a reviewer must send it back to needs_correction before a teacher can touch its scores again.
        $lockedStudentIds = ReportCardReview::where('term_id', $data['term_id'])
            ->whereIn('student_id', $studentIds)
            ->whereIn('status', ReportCardReview::LOCKED_FOR_EDITING)
            ->pluck('student_id')
            ->all();

        // Assessment score-entry windows (user request 2026-07-18): a role
        // with blanket access (the same one that bypasses allowedPairs()'s
        // "which class/subject" restriction) is never window-restricted,
        // since they are the ones who set the window in the first place.
        // Checked once per component, not per student row.
        $blanket = $this->hasBlanketAccess($request);
        $staff = Staff::where('user_id', Auth::id())->first();
        $componentOpen = [];
        if (! $blanket) {
            foreach ($components as $component) {
                $componentOpen[$component->id] = $windows->isOpenFor($component, $term, $staff);
            }
        }

        $toSave = [];
        $errors = [];
        $lockedSkipped = 0;
        $windowSkipped = 0;
        $windowClosedComponents = [];

        foreach ($request->input('scores', []) as $studentId => $componentScores) {
            if (! in_array((int) $studentId, $studentIds, true)) {
                continue;
            }
            if (in_array((int) $studentId, $lockedStudentIds, true)) {
                $lockedSkipped++;

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
                if (! $blanket && ! ($componentOpen[$component->id] ?? true)) {
                    $windowSkipped++;
                    $windowClosedComponents[$component->name] = true;

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

        $message = count($toSave).' score'.(count($toSave) === 1 ? '' : 's').' saved.';
        if ($windowSkipped > 0) {
            $message .= " {$windowSkipped} score(s) skipped, the entry window is closed for ".implode(', ', array_keys($windowClosedComponents)).'. Request an extension from this screen if you still need to enter these.';
        }
        if ($lockedSkipped > 0) {
            $message .= " {$lockedSkipped} student(s) skipped because their report card is locked for editing, send it back for correction first.";
        }

        return redirect()->route('school.examinations.index', [
            'school' => $school->slug,
            'term_id' => $data['term_id'],
            'pair' => $data['class_arm_id'].':'.$data['subject_id'],
        ])->with('status', $message);
    }

    /**
     * Which subjects a bulk template/upload covers for one class arm: a
     * single subject if given, or every subject in allowedPairs() for
     * that class arm (user directive 2026-07-12: "a teacher can
     * determine to do bulk upload for just one subject also", implying
     * the default, no subject given, covers everything they teach there).
     */
    protected function bulkSubjectIds(Request $request, int $termId, int $classArmId, ?int $subjectId): array
    {
        $pairs = $this->allowedPairs($request, $termId)->where('class_arm_id', $classArmId);
        if ($subjectId) {
            $pairs = $pairs->where('subject_id', $subjectId);
        }

        return $pairs->pluck('subject_id')->unique()->values()->all();
    }

    /** "Mathematics: CA1", not a dash separated string (standing no-dashes-in-on-screen-text rule), this header is genuinely user facing, it opens directly in Excel. */
    protected function bulkColumnHeader(string $subjectName, string $componentName): string
    {
        return "{$subjectName}: {$componentName}";
    }

    /**
     * A pre-filled CSV for one class arm: student admission number and
     * name, then one column per (subject, assessment component) pair in
     * scope, existing scores already filled in so this doubles as an
     * export, not just a blank form (user directive 2026-07-12: "download
     * the template that has the names, subjects... fill it and upload it
     * back").
     */
    public function bulkTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'examinations.create');
        $data = $request->validate([
            'term_id' => ['required', 'integer'],
            'class_arm_id' => ['required', 'integer'],
            'subject_id' => ['nullable', 'integer'],
        ]);

        $subjectIds = $this->bulkSubjectIds($request, (int) $data['term_id'], (int) $data['class_arm_id'], isset($data['subject_id']) ? (int) $data['subject_id'] : null);
        abort_if(empty($subjectIds), 403, 'You do not teach a subject in this class.');

        $subjects = Subject::whereIn('id', $subjectIds)->get()->keyBy('id');
        $components = AssessmentComponent::orderBy('sequence')->get();
        $students = Student::active()->where('current_class_arm_id', $data['class_arm_id'])->orderBy('first_name')->get();

        $existing = Score::where('class_arm_id', $data['class_arm_id'])
            ->where('term_id', $data['term_id'])
            ->whereIn('subject_id', $subjectIds)
            ->get()
            ->keyBy(fn ($s) => $s->student_id.':'.$s->subject_id.':'.$s->assessment_component_id);

        $out = fopen('php://temp', 'r+');
        $header = ['admission_number', 'name'];
        foreach ($subjectIds as $subjectId) {
            foreach ($components as $component) {
                $header[] = $this->bulkColumnHeader($subjects[$subjectId]->name, $component->name);
            }
        }
        fputcsv($out, $header);

        foreach ($students as $student) {
            $row = [$student->admission_number, $student->fullName()];
            foreach ($subjectIds as $subjectId) {
                foreach ($components as $component) {
                    $score = $existing->get($student->id.':'.$subjectId.':'.$component->id)?->score;
                    $row[] = $score !== null ? rtrim(rtrim(number_format($score, 2), '0'), '.') : '';
                }
            }
            fputcsv($out, $row);
        }

        rewind($out);
        $csv = (string) stream_get_contents($out);
        $classArm = ClassArm::find($data['class_arm_id']);
        $filename = 'scores-'.\Illuminate\Support\Str::slug($classArm?->label() ?? 'class').'.csv';

        return response($csv, 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => "attachment; filename=\"{$filename}\"",
        ]);
    }

    /**
     * Re-upload of the template above. Which subjects are actually acted
     * on is read from the file's own header columns (matched against
     * every subject this teacher is allowed to enter for this class
     * arm), not a separate parameter, since one template may cover a
     * single subject or every subject at once and either must upload
     * back correctly. A blank cell is skipped, never treated as a zero
     * (same "fill in what changed" convention Houses' bulk CSV already
     * uses), so a partially filled sheet never wipes a score no one
     * meant to touch. The system checks every entered value is numeric
     * and within the component's real max score (user directive
     * 2026-07-12: "the system makes sure the data entered... are
     * correct"), an out of range cell is skipped and reported, valid
     * cells in the same upload still save.
     */
    public function bulkUpload(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'examinations.create');
        $school = $this->school($request);

        $data = $request->validate([
            'term_id' => ['required', 'integer'],
            'class_arm_id' => ['required', 'integer'],
            'file' => ['required', 'file', 'mimes:csv,txt', 'max:5120'],
        ]);

        $allowedSubjectIds = $this->bulkSubjectIds($request, (int) $data['term_id'], (int) $data['class_arm_id'], null);
        abort_if(empty($allowedSubjectIds), 403, 'You do not teach a subject in this class.');

        $subjects = Subject::whereIn('id', $allowedSubjectIds)->get()->keyBy('id');
        $components = AssessmentComponent::orderBy('sequence')->get();

        $columnMap = [];
        foreach ($subjects as $subject) {
            foreach ($components as $component) {
                $header = strtolower($this->bulkColumnHeader($subject->name, $component->name));
                $columnMap[$header] = [
                    'subject_id' => $subject->id,
                    'component_id' => $component->id,
                    'max' => (float) $component->max_score,
                    'label' => "{$subject->name}: {$component->name}",
                ];
            }
        }

        $csv = file_get_contents($request->file('file')->getRealPath());
        try {
            $rows = app(CsvReader::class)->rows($csv);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }

        $students = Student::active()->where('current_class_arm_id', $data['class_arm_id'])->get()->keyBy('admission_number');
        $lockedStudentIds = ReportCardReview::where('term_id', $data['term_id'])
            ->whereIn('student_id', $students->pluck('id'))
            ->whereIn('status', ReportCardReview::LOCKED_FOR_EDITING)
            ->pluck('student_id')
            ->all();

        $saved = 0;
        $errors = [];
        $lockedSkipped = 0;

        foreach ($rows as $index => $row) {
            $rowNumber = $index + 2;
            $admissionNumber = trim((string) ($row['admission_number'] ?? ''));
            if ($admissionNumber === '') {
                continue;
            }
            $student = $students->get($admissionNumber);
            if (! $student) {
                $errors[] = "Row {$rowNumber}: no student with admission number \"{$admissionNumber}\" in this class.";

                continue;
            }
            if (in_array($student->id, $lockedStudentIds, true)) {
                $lockedSkipped++;

                continue;
            }

            foreach ($row as $columnHeader => $value) {
                if (! isset($columnMap[$columnHeader]) || $value === null || $value === '') {
                    continue;
                }
                $col = $columnMap[$columnHeader];
                if (! is_numeric($value) || $value < 0 || $value > $col['max']) {
                    $errors[] = "Row {$rowNumber} ({$admissionNumber}): \"{$col['label']}\" must be between 0 and {$col['max']}.";

                    continue;
                }

                Score::updateOrCreate(
                    [
                        'student_id' => $student->id,
                        'subject_id' => $col['subject_id'],
                        'term_id' => (int) $data['term_id'],
                        'assessment_component_id' => $col['component_id'],
                    ],
                    [
                        'class_arm_id' => (int) $data['class_arm_id'],
                        'score' => (float) $value,
                    ]
                );
                $saved++;
            }
        }

        $message = $saved.' score'.($saved === 1 ? '' : 's').' saved.';
        if ($lockedSkipped > 0) {
            $message .= " {$lockedSkipped} student(s) skipped because their report card is locked for editing, send it back for correction first.";
        }
        if ($errors) {
            $message .= ' '.count($errors).' issue(s), first: '.$errors[0];
        }

        return redirect()->route('school.examinations.index', [
            'school' => $school->slug,
            'term_id' => $data['term_id'],
            'pair' => $data['class_arm_id'].':'.$allowedSubjectIds[0],
        ])->with($errors ? 'error' : 'status', $message);
    }
}
