<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
use App\Services\Rbac\StudentAccessGuard;
use App\Services\Rbac\TeacherScope;
use App\Services\Media\ImageProcessingService;
use App\Services\ReportCards\ReportCardLayout;
use App\Services\ReportCards\ReportCardPreferences;
use App\Services\ReportCards\ReportCardService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Report card template/field settings, a synthetic preview so an admin can
 * compare templates before any real scores exist, and the real per student
 * render once they do.
 *
 * Scalar route ids/values are read via $request->route(...) rather than
 * typed method parameters: these routes sit under a domain wildcard
 * ({school}.<domain>), and Laravel resolves untyped scalar controller
 * parameters positionally against ALL route parameters including the
 * domain one, not by name — so a typed parameter would silently receive
 * the school slug instead of the intended value.
 */
class ReportCardController extends Controller
{
    /**
     * Admin toggle for whether a generated report card must go through
     * pending review / approve / reject before release (user request
     * 2026-07-11: "Admin should be able to turn off or on the generated
     * report card review stage"). Off skips straight to released.
     */
    public const SETTING_REVIEW_ENABLED = 'report_card_review_enabled';

    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function reviewStageEnabled(): bool
    {
        return (bool) Setting::grab(self::SETTING_REVIEW_ENABLED, true);
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
     * A class/subject teacher holds report_cards.view/create/update but was
     * never scope checked on report card actions the way
     * StudentController::show() checks students (audit finding M-1,
     * 2026-07-17): they could view/generate/send back a report card for any
     * student school wide, not just their own class. 404, never 403, same
     * convention as StudentAccessGuard.
     */
    protected function assertStudentVisible(Request $request, Student $student): void
    {
        app(StudentAccessGuard::class)->assertVisible(Auth::user(), $this->school($request), $student);
    }

    public function edit(Request $request, ReportCardPreferences $prefs): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);

        return view('school.report-cards.settings', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'currentTemplate' => $prefs->template(),
            'fields' => $prefs->fields(),
            'reviewStageEnabled' => $this->reviewStageEnabled(),
            'reviewChain' => ReportCardReview::reviewChain(),
            'reviewChainRoles' => config('skulos.report_card_review_roles'),
        ]);
    }

    /** Turns the review stage on or off school wide, an admin level decision, not a plain teacher one. */
    public function updateReviewStage(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        Setting::put(self::SETTING_REVIEW_ENABLED, $data['enabled']);

        return back()->with('status', $data['enabled']
            ? 'The report card review stage is on. Generated report cards now need approval before release.'
            : 'The report card review stage is off. Generated report cards are released immediately.');
    }

    /**
     * The review chain itself (user directive 2026-07-12: "Admin
     * configures Workflow... Entirely configurable"): an ordered list of
     * role slugs a report card must pass through, in order, before it can
     * be released. Stored as tenant Setting "report_card_review_chain",
     * defaulting to config('skulos.default_report_card_review_chain')
     * (single stage, the original 2026-07-11 behaviour) until a school
     * deliberately changes it.
     */
    public function updateReviewChain(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $data = $request->validate([
            'chain' => ['required', 'array', 'min:1'],
            'chain.*' => ['nullable', 'string', Rule::in(array_keys(config('skulos.report_card_review_roles')))],
        ]);

        // The chain builder submits one slot per stage; a blank slot (a stage
        // left "Not used") stops the chain there, anything after it is ignored.
        $chain = [];
        foreach ($data['chain'] as $roleSlug) {
            if (! $roleSlug) {
                break;
            }
            $chain[] = $roleSlug;
        }
        abort_if(empty($chain), 422, 'The review chain needs at least one stage.');

        Setting::put('report_card_review_chain', $chain);

        return back()->with('status', 'Report card review chain updated.');
    }

    public function update(Request $request, ReportCardPreferences $prefs): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $data = $request->validate([
            'template' => ['required', 'string', Rule::in(array_keys(config('skulos.report_card_templates')))],
            'fields' => ['array'],
        ]);

        $prefs->setTemplate($data['template']);
        $prefs->setFields($request->input('fields', []));

        return back()->with('status', 'Your report card look has been updated.');
    }

    /** The active grading scale's bands as the render view's grade key legend, most generous band first. */
    protected function gradeBands(): array
    {
        $scale = \App\Models\Tenant\GradingScale::default();

        return $scale
            ? $scale->bands()->orderByDesc('min_score')->get(['grade', 'min_score', 'max_score', 'remark'])->toArray()
            : [];
    }

    public function preview(Request $request, ReportCardService $cards, ReportCardPreferences $prefs, ReportCardLayout $layout): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);
        $templates = array_keys(config('skulos.report_card_templates'));
        $requested = $request->route('template');
        $template = in_array($requested, $templates, true) ? $requested : $prefs->template();

        return view('school.report-cards.render', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'template' => $template,
            'fields' => $prefs->fields(),
            'data' => $cards->sample(),
            'gradeBands' => $this->gradeBands(),
            'layoutEnabled' => $layout->enabled(),
            'layoutZones' => $layout->zones(),
            'bodySections' => $layout->effectiveBodyOrder($layout->enabled()),
            'headerAlign' => $layout->headerAlign(),
            'isPreview' => true,
        ]);
    }

    public function show(Request $request, ReportCardService $cards, ReportCardPreferences $prefs, ReportCardLayout $layout): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);
        $student = Student::with('currentClassArm.schoolClass')->findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = Term::findOrFail($request->route('termId'));

        // Narrative Evaluation (2026-07-18): overrides the school's own
        // global template choice for this one student's class, same
        // reasoning as classEntry() above.
        $narrativeClass = $student->currentClassArm?->schoolClass?->isNarrativeEvaluation();

        return view('school.report-cards.render', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'template' => $narrativeClass ? 'narrative' : $prefs->template(),
            'fields' => $prefs->fields(),
            'data' => $cards->forStudent($student, $term),
            'gradeBands' => $this->gradeBands(),
            'layoutEnabled' => $layout->enabled(),
            'layoutZones' => $layout->zones(),
            'bodySections' => $layout->effectiveBodyOrder($layout->enabled()),
            'headerAlign' => $layout->headerAlign(),
            'isPreview' => false,
        ]);
    }

    /**
     * The drag-and-drop header/identity-strip designer (user request
     * 2026-07-18: "let's make the report card a widget approach and drag
     * and drop approach"; rebuilt 2026-07-18 same day after user feedback
     * "the report card designer is poorly done... there should be a live
     * preview of how it will look like which is where all the items
     * should also be dragged to or remove"). Every block is pre-rendered
     * here through the exact same partial (_layout-block.blade.php) the
     * real report card uses, with the same sample data ReportCardService::
     * sample() already provides for the Template preview screen, so what
     * the admin drags around IS the real markup, not a mockup that could
     * drift from it. Same view permission as the settings page it's
     * reached from.
     */
    public function editLayout(Request $request, ReportCardLayout $layout, ReportCardService $cards, ReportCardPreferences $prefs): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);
        $sample = $cards->sample();
        $logoUrl = $school->logo_path ? \Illuminate\Support\Facades\Storage::url($school->logo_path) : null;
        $template = $prefs->template();

        $blockLabels = config('skulos.report_card_layout_blocks', []);
        $blockHtml = [];
        foreach (array_keys($blockLabels) as $key) {
            $blockHtml[$key] = view('school.report-cards._layout-block', [
                'block' => $key, 'data' => $sample, 'school' => $school, 'logoUrl' => $logoUrl,
            ])->render();
        }

        // Body sections (2026-07-18, "make it much more holistic"): the
        // same real markup the actual report card uses, same sample
        // data, rendered with the school's own current template choice
        // so what an admin arranges here matches what they will
        // actually see (compact hides columns, modern adds extras, etc).
        $bodyLabels = config('skulos.report_card_body_sections', []);
        $bodyHtml = [];
        foreach (array_keys($bodyLabels) as $key) {
            $bodyHtml[$key] = view('school.report-cards._body-'.$key, [
                'template' => $template,
                'show' => fn ($f) => $prefs->shows($f),
                'data' => $sample,
                'gradeBands' => $this->gradeBands(),
                'isEval' => in_array($template, ['evaluation', 'narrative'], true),
            ])->render();
        }

        return view('school.report-cards.layout', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'enabled' => $layout->enabled(),
            'zones' => $layout->zones(),
            'blocks' => $blockLabels,
            'blockHtml' => $blockHtml,
            'headerAlign' => $layout->headerAlign(),
            'bodySections' => $layout->bodyOrder(),
            'bodyLabels' => $bodyLabels,
            'bodyHtml' => $bodyHtml,
        ]);
    }

    public function updateLayout(Request $request, ReportCardLayout $layout): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $validBlocks = array_keys(config('skulos.report_card_layout_blocks', []));
        $validBodySections = array_keys(config('skulos.report_card_body_sections', []));

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'header' => ['array'],
            'header.*' => [Rule::in($validBlocks)],
            'info' => ['array'],
            'info.*' => [Rule::in($validBlocks)],
            'header_align' => ['array'],
            'header_align.*' => [Rule::in(['left', 'center', 'right'])],
            'body' => ['array'],
            'body.*' => [Rule::in($validBodySections)],
        ]);

        $layout->setEnabled((bool) ($data['enabled'] ?? false));
        $layout->setZones(['header' => $data['header'] ?? [], 'info' => $data['info'] ?? []]);
        $layout->setHeaderAlign($data['header_align'] ?? []);
        $layout->setBodyOrder($data['body'] ?? []);

        return back()->with('status', 'Report card layout saved.');
    }

    /**
     * Main Report Cards landing page (user request 2026-07-11): every
     * section, then the classes in the school, "Report card look" is
     * reached from here as a sub link, not the destination of the main
     * nav item anymore.
     */
    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);
        // A school with no session/term set up yet must still see the
        // (empty) browse page, not a hard 404: resolveTerm() aborts via
        // currentTermOrAbort() when there is truly nothing to fall back
        // to, which is correct for every *action* below (you cannot
        // generate a report card against a term that does not exist) but
        // wrong for this page, whose whole job is to be reachable before
        // any of that setup exists (found 2026-07-16 running the fuller
        // test suite after adding the term picker: a fresh school with no
        // term at all now 404'd on its own Report Cards landing page).
        $termId = $request->integer('term_id') ?: null;
        $term = ($termId ? Term::find($termId) : null) ?? Term::current()->first();

        return view('school.report-cards.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'sections' => Section::with(['classes' => fn ($q) => $q->orderBy('sequence')])->orderBy('sequence')->get(),
            'canAssign' => app(Rbac::class)->check(Auth::user(), $school, 'report_cards.assign_review'),
            // "Results" and "Report Cards" merged into one nav line (user
            // directive 2026-07-15), so the results/score entry screen
            // (school.examinations.index) needs to stay reachable from
            // here instead, not disappear.
            'canViewResults' => app(Rbac::class)->check(Auth::user(), $school, 'examinations.view'),
            // The old standalone Results screen let a teacher pick any
            // session/term, not just the current one; Report Cards lost
            // that when the two merged (user report 2026-07-16). Restored
            // here: every term flows through as ?term_id= from this point
            // on, to classIndex and every review action.
            'terms' => Term::with('session')->orderByDesc('starts_on')->get(),
            'selectedTerm' => $term,
        ]);
    }

    protected function currentTermOrAbort(): Term
    {
        $term = Term::current()->first();
        abort_unless($term, 404, 'There is no current term set. Set one under Settings > Sessions & Terms first.');

        return $term;
    }

    /**
     * Which term a request is acting on: an explicit `term_id` (the
     * session/term picker the old standalone Results screen had, that
     * Report Cards lost when the two were merged into one nav line, user
     * report 2026-07-16: "there are some features under the previous
     * result page, that is not under the current report page. such as one
     * can select a session, term...") if given and real, otherwise the
     * school's current term, exactly as before this existed. Read via
     * input() so it works from both a GET query string (browsing) and a
     * POST body (every review action below, via a hidden field), not just
     * one or the other.
     */
    protected function resolveTerm(Request $request): Term
    {
        $termId = $request->input('term_id');
        if ($termId && $term = Term::find($termId)) {
            return $term;
        }

        return $this->currentTermOrAbort();
    }

    /**
     * One class's students with their report card review status for the
     * selected (or current) term (user request 2026-07-11: "from each one
     * can either view a report card of a student, generate report card,
     * review it, send it back... or single/bulk assign some class report
     * to a staff for review").
     */
    public function classIndex(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);
        $class = SchoolClass::with(['section', 'arms'])->findOrFail($request->route('classId'));
        $term = $this->resolveTerm($request);

        $armIds = $class->arms->pluck('id');

        // A class/subject teacher opening a class outside their own scope
        // gets a 404, matching every other scoped student/report-card view
        // (audit finding M-1, 2026-07-17).
        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        if ($scopedArmIds !== null) {
            abort_unless($armIds->intersect($scopedArmIds)->isNotEmpty(), 404);
        }

        $students = Student::whereIn('current_class_arm_id', $armIds)->orderBy('last_name')->get();
        $reviews = ReportCardReview::whereIn('student_id', $students->pluck('id'))
            ->where('term_id', $term->id)
            ->with('assignedTo')
            ->get()
            ->keyBy('student_id');

        return view('school.report-cards.class-index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'class' => $class,
            'term' => $term,
            'students' => $students,
            'reviews' => $reviews,
            'staffOptions' => Staff::active()->orderBy('first_name')->get(),
            'canAssign' => app(Rbac::class)->check(Auth::user(), $school, 'report_cards.assign_review'),
            'canRelease' => app(Rbac::class)->check(Auth::user(), $school, 'report_cards.release'),
            'reviewChainRoles' => config('skulos.report_card_review_roles'),
        ]);
    }

    /**
     * Builds the initial attributes for a newly generated review: the
     * review stage toggle decides whether it starts pending review or
     * goes straight to released, and the class's default reviewer (if
     * any) is auto assigned rather than left blank.
     */
    protected function newReviewAttributes(?int $defaultReviewerStaffId): array
    {
        if (! $this->reviewStageEnabled()) {
            return ['status' => ReportCardReview::STATUS_RELEASED, 'released_at' => now()];
        }

        return [
            'status' => ReportCardReview::STATUS_PENDING_REVIEW,
            'assigned_to_staff_id' => $defaultReviewerStaffId,
        ];
    }

    /** Ensures a review row exists for a student's current term report card, the "Generate" action. */
    public function generate(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.create');
        $student = Student::with('currentClassArm.schoolClass')->findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = $this->resolveTerm($request);

        $review = ReportCardReview::firstOrCreate(
            ['student_id' => $student->id, 'term_id' => $term->id],
            $this->newReviewAttributes($student->currentClassArm?->schoolClass?->default_reviewer_staff_id)
        );

        return back()->with('status', "{$student->fullName()}'s report card is ready to review.");
    }

    /** Generate every student in a class at once. */
    public function generateClass(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.create');
        $class = SchoolClass::with('arms')->findOrFail($request->route('classId'));
        $term = $this->resolveTerm($request);
        $armIds = $class->arms->pluck('id');
        $studentIds = Student::whereIn('current_class_arm_id', $armIds)->pluck('id');

        $created = 0;
        foreach ($studentIds as $studentId) {
            $review = ReportCardReview::firstOrNew(['student_id' => $studentId, 'term_id' => $term->id]);
            if (! $review->exists) {
                $review->fill($this->newReviewAttributes($class->default_reviewer_staff_id));
                $review->save();
                $created++;
            }
        }

        return back()->with('status', "{$created} report card(s) generated.");
    }

    /** Which staff member auto reviews every report card generated for this class (user request 2026-07-11). */
    public function updateDefaultReviewer(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $class = SchoolClass::findOrFail($request->route('classId'));
        $data = $request->validate(['default_reviewer_staff_id' => ['nullable', 'exists:tenant.staff,id']]);

        $class->update(['default_reviewer_staff_id' => $data['default_reviewer_staff_id'] ?: null]);

        return back()->with('status', "Default reviewer for {$class->name} updated.");
    }

    /** Send a report card back to a staff member (class teacher, subject teacher, or anyone else) for correction. */
    public function sendBack(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $student = Student::findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = $this->resolveTerm($request);

        $data = $request->validate([
            'assigned_to_staff_id' => ['required', 'exists:tenant.staff,id'],
            'notes' => ['required', 'string', 'max:1000'],
        ], [
            'notes.required' => 'A comment is required when sending a report card back for correction.',
        ]);

        $review = ReportCardReview::firstOrNew(['student_id' => $student->id, 'term_id' => $term->id]);
        $review->fill([
            'status' => ReportCardReview::STATUS_NEEDS_CORRECTION,
            // A correction restarts the chain from the first stage, the
            // person fixing it is not necessarily wherever review_stage
            // last pointed to.
            'review_stage' => 0,
            'assigned_to_staff_id' => $data['assigned_to_staff_id'],
            'notes' => $data['notes'] ?? null,
        ])->save();

        return back()->with('status', "{$student->fullName()}'s report card sent back for correction.");
    }

    /** Single assign: who is reviewing this one report card. */
    public function assignReview(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $student = Student::findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = $this->resolveTerm($request);

        $data = $request->validate(['assigned_to_staff_id' => ['nullable', 'exists:tenant.staff,id']]);

        $review = ReportCardReview::firstOrNew(['student_id' => $student->id, 'term_id' => $term->id]);
        if (! $review->exists) {
            $review->status = ReportCardReview::STATUS_PENDING_REVIEW;
        }
        $review->assigned_to_staff_id = $data['assigned_to_staff_id'] ?: null;
        $review->save();

        return back()->with('status', 'Updated.');
    }

    /**
     * Bulk assign: a whole class's (or a ticked subset's) report cards
     * to one staff member for review in one action (user request
     * 2026-07-11: "single or bulk assign some class report to a staff
     * for review"), matching the standing bulk-actions convention.
     */
    public function bulkAssignReview(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $school = $this->school($request);
        $term = $this->resolveTerm($request);

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['exists:tenant.students,id'],
            'assigned_to_staff_id' => ['required', 'exists:tenant.staff,id'],
        ]);

        // A scoped teacher's id list is narrowed to their own classes, an
        // id crafted for another class is silently dropped rather than
        // acted on, matching StudentController::bulkAction()'s convention.
        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        $studentIds = $scopedArmIds !== null
            ? Student::whereIn('id', $data['student_ids'])->whereIn('current_class_arm_id', $scopedArmIds)->pluck('id')
            : collect($data['student_ids']);

        $count = 0;
        foreach ($studentIds as $studentId) {
            $review = ReportCardReview::firstOrNew(['student_id' => $studentId, 'term_id' => $term->id]);
            if (! $review->exists) {
                $review->status = ReportCardReview::STATUS_PENDING_REVIEW;
            }
            $review->assigned_to_staff_id = $data['assigned_to_staff_id'];
            $review->save();
            $count++;
        }

        return back()->with('status', "{$count} report card(s) assigned for review.");
    }

    /**
     * Approve: satisfies the CURRENT stage of the school's review chain
     * (user directive 2026-07-12: "Admin configures Workflow... Entirely
     * configurable"). At any stage before the last, this advances
     * review_stage and stays pending_review, ready for the next role in
     * the chain. At the final stage, exactly like the original single
     * stage behaviour, it becomes approved and ready to release.
     */
    public function approve(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $student = Student::findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = $this->resolveTerm($request);

        $review = ReportCardReview::firstOrNew(['student_id' => $student->id, 'term_id' => $term->id]);

        if ($review->isFinalStage()) {
            $review->status = ReportCardReview::STATUS_APPROVED;
            $review->save();

            return back()->with('status', "{$student->fullName()}'s report card approved.");
        }

        $review->review_stage++;
        $review->status = ReportCardReview::STATUS_PENDING_REVIEW;
        $review->save();

        $nextRole = config('skulos.report_card_review_roles')[$review->currentStageRole()] ?? $review->currentStageRole();

        return back()->with('status', "{$student->fullName()}'s report card moved to the next review stage ({$nextRole}).");
    }

    /**
     * Promotion recommendation (user directive 2026-07-12: "Class Teacher
     * completes Promotion: Promote / Repeat / Conditional Promotion,
     * Reason"). Not stage gated, any reviewer along the chain can set or
     * change it, matching "Approval Workflow" from the doc being about
     * the report card as a whole, not this one field specifically.
     */
    public function updatePromotion(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $student = Student::findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = $this->resolveTerm($request);

        $data = $request->validate([
            'promotion_recommendation' => ['required', Rule::in([
                ReportCardReview::PROMOTION_PROMOTE, ReportCardReview::PROMOTION_REPEAT, ReportCardReview::PROMOTION_CONDITIONAL,
            ])],
            'promotion_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = ReportCardReview::firstOrNew(['student_id' => $student->id, 'term_id' => $term->id]);
        $review->fill($data)->save();

        return back()->with('status', "{$student->fullName()}'s promotion recommendation saved.");
    }

    /** Release: visible to students/parents from this point (once that portal exists). */
    public function release(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.release');
        $student = Student::findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = $this->resolveTerm($request);

        $review = ReportCardReview::firstOrNew(['student_id' => $student->id, 'term_id' => $term->id]);
        $review->status = ReportCardReview::STATUS_RELEASED;
        $review->released_at = now();
        $review->save();

        return back()->with('status', "{$student->fullName()}'s report card released.");
    }

    /**
     * Lock: a stronger, deliberate "this is truly final" past released
     * (user directive 2026-07-12, docx flow: "...Publish → Parents View
     * → Archive", "Lock" sits between Approve and Publish in the source
     * flow, implemented here as an optional step after release instead,
     * so a school that never locks anything keeps behaving exactly as
     * before this existed). See ExaminationController::save(), a locked
     * review blocks further score edits for this student's term the
     * same as approved/released already do.
     */
    public function lock(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.release');
        $student = Student::findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = $this->resolveTerm($request);

        $review = ReportCardReview::where('student_id', $student->id)->where('term_id', $term->id)->firstOrFail();
        abort_unless($review->status === ReportCardReview::STATUS_RELEASED, 422, 'Only a released report card can be locked.');

        $review->status = ReportCardReview::STATUS_LOCKED;
        $review->locked_at = now();
        $review->save();

        return back()->with('status', "{$student->fullName()}'s report card locked.");
    }

    /** Archive: takes a released or locked report card out of active lists once its term is over. */
    public function archive(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.release');
        $student = Student::findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = $this->resolveTerm($request);

        $review = ReportCardReview::where('student_id', $student->id)->where('term_id', $term->id)->firstOrFail();
        abort_unless(
            in_array($review->status, [ReportCardReview::STATUS_RELEASED, ReportCardReview::STATUS_LOCKED], true),
            422,
            'Only a released or locked report card can be archived.'
        );

        $review->status = ReportCardReview::STATUS_ARCHIVED;
        $review->archived_at = now();
        $review->save();

        return back()->with('status', "{$student->fullName()}'s report card archived.");
    }

    /**
     * School wide list of every report card still awaiting a decision
     * (user request 2026-07-11: "a section under report and analytics to
     * see reports pending review... nudge the person or review to
     * approve or reject"), across every class rather than one at a time.
     */
    public function pendingReview(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $school = $this->school($request);
        $term = $this->currentTermOrAbort();

        $reviews = ReportCardReview::where('term_id', $term->id)
            ->whereIn('status', [ReportCardReview::STATUS_PENDING_REVIEW, ReportCardReview::STATUS_NEEDS_CORRECTION])
            ->with(['student.currentClassArm.schoolClass', 'assignedTo'])
            ->orderBy('updated_at')
            ->get();

        return view('school.report-cards.pending-review', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'term' => $term,
            'reviews' => $reviews,
            'staffOptions' => Staff::active()->orderBy('first_name')->get(),
            'sections' => Section::with(['classes' => fn ($q) => $q->active()->orderBy('sequence')])->orderBy('sequence')->get(),
            'reviewStageEnabled' => $this->reviewStageEnabled(),
        ]);
    }

    /**
     * Reminds the assigned reviewer via an in app notification. Does
     * nothing to the review's status, purely a nudge.
     */
    public function nudge(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $student = Student::findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = $this->currentTermOrAbort();

        $review = ReportCardReview::where('student_id', $student->id)->where('term_id', $term->id)->firstOrFail();
        $staff = $review->assignedTo;

        if (! $staff) {
            return back()->with('error', 'Assign this report card to a staff member before nudging them.');
        }
        if (! $staff->user_id) {
            return back()->with('error', "{$staff->fullName()} has no login yet, so they can't be nudged in app.");
        }

        \App\Models\Tenant\Notification::create([
            'user_id' => $staff->user_id,
            'title' => 'Report card awaiting your review',
            'body' => "{$student->fullName()}'s report card is waiting on you to review, approve, or send back.",
            'category' => 'report_card_review',
            'type' => \App\Models\Tenant\Notification::typeFor('report_card_review'),
        ]);

        return back()->with('status', "{$staff->fullName()} has been nudged.");
    }

    /**
     * Class Teacher Entry (user request 2026-07-18, from 2 real report
     * card templates supplied as reference: behavioural/psychomotor
     * traits, physical stats, and remarks, "this section is only for the
     * class teachers to enter"). One page, one row per student, bulk
     * saved in a single submit (the standing bulk-actions convention),
     * the same shape AttendanceController::index()'s class-arm picker
     * already uses. class_teacher_remark is editable by anyone with
     * report_cards.update (the class teacher); principal_remark needs
     * the stronger report_cards.release permission the same way
     * approve()/release() already require it above, a class teacher does
     * not hold it, so that column is simply hidden for them, not merely
     * disabled.
     */
    public function classEntry(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.update');
        $school = $this->school($request);
        $user = Auth::user();

        $class = SchoolClass::with(['section', 'arms'])->findOrFail($request->route('classId'));
        $term = $this->resolveTerm($request);

        $armIds = $class->arms->pluck('id');
        $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);
        if ($scopedArmIds !== null) {
            abort_unless($armIds->intersect($scopedArmIds)->isNotEmpty(), 404);
        }

        app(\App\Services\Results\BehaviouralTraitCatalog::class)->ensureSeeded();
        $traits = \App\Models\Tenant\BehaviouralTrait::active()->orderBy('sequence')->get();

        $students = Student::whereIn('current_class_arm_id', $armIds)->active()->orderBy('last_name')->get();
        $studentIds = $students->pluck('id');

        $remarks = \App\Models\Tenant\StudentTermRemark::whereIn('student_id', $studentIds)
            ->where('term_id', $term->id)->get()->keyBy('student_id');
        $ratings = \App\Models\Tenant\StudentBehaviouralRating::whereIn('student_id', $studentIds)
            ->where('term_id', $term->id)->get()->groupBy('student_id');

        $prefs = app(ReportCardPreferences::class);
        // Narrative Evaluation (2026-07-18): a class flagged evaluation_style
        // 'narrative' always uses the comment based template here, regardless
        // of the school's own global template choice, so a school can mix a
        // scored Primary/Secondary with a narrative Creche/Reception rather
        // than the template being one all-or-nothing school wide setting.
        $template = $class->isNarrativeEvaluation() ? 'narrative' : $prefs->template();
        $subjects = collect();
        $comments = collect();
        // Comments are entered here whenever the active look actually shows
        // them: always for the comments-only "narrative" template, or for
        // any other template a school turned the optional "subject_
        // comments" field on for (found in QA 2026-07-18: without this, a
        // school on Classic/Modern/etc. with that field on had nowhere to
        // ever enter one).
        if ($template === 'narrative' || $prefs->shows('subject_comments')) {
            $subjectIds = \App\Models\Tenant\TeachingAssignment::whereIn('class_arm_id', $armIds)->pluck('subject_id')->unique();
            $subjects = \App\Models\Tenant\Subject::whereIn('id', $subjectIds)->orderBy('name')->get();
            $comments = \App\Models\Tenant\StudentSubjectComment::whereIn('student_id', $studentIds)
                ->where('term_id', $term->id)->get()->groupBy(fn ($c) => $c->student_id.'-'.$c->subject_id);
        }

        return view('school.report-cards.class-entry', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'class' => $class,
            'term' => $term,
            'students' => $students,
            'traits' => $traits,
            'remarks' => $remarks,
            'ratings' => $ratings,
            'subjects' => $subjects,
            'comments' => $comments,
            'template' => $template,
            'canEnterPrincipalRemark' => app(Rbac::class)->check($user, $school, 'report_cards.release'),
        ]);
    }

    public function classEntryUpdate(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $school = $this->school($request);
        $user = Auth::user();

        $class = SchoolClass::with('arms')->findOrFail($request->route('classId'));
        $term = $this->resolveTerm($request);
        $armIds = $class->arms->pluck('id');
        $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);
        if ($scopedArmIds !== null) {
            abort_unless($armIds->intersect($scopedArmIds)->isNotEmpty(), 404);
        }
        // A crafted student_id outside this class is silently dropped,
        // the same "never act on an id outside scope" convention every
        // other bulk action in this codebase already follows.
        $validStudentIds = Student::whereIn('current_class_arm_id', $armIds)->pluck('id');

        $canEnterPrincipalRemark = app(Rbac::class)->check($user, $school, 'report_cards.release');
        $data = $request->input('students', []);

        foreach ($data as $studentId => $row) {
            if (! $validStudentIds->contains((int) $studentId)) {
                continue;
            }

            $remarkData = [
                'class_teacher_remark' => $row['class_teacher_remark'] ?? null,
                'height' => $row['height'] ?? null,
                'weight' => $row['weight'] ?? null,
                'club' => $row['club'] ?? null,
            ];
            if ($canEnterPrincipalRemark) {
                $remarkData['principal_remark'] = $row['principal_remark'] ?? null;
            }
            \App\Models\Tenant\StudentTermRemark::updateOrCreate(
                ['student_id' => $studentId, 'term_id' => $term->id],
                $remarkData
            );

            foreach (($row['traits'] ?? []) as $traitId => $value) {
                if ($value === '' || $value === null) {
                    continue;
                }
                \App\Models\Tenant\StudentBehaviouralRating::updateOrCreate(
                    ['student_id' => $studentId, 'term_id' => $term->id, 'behavioural_trait_id' => $traitId],
                    ['value' => $value]
                );
            }

            foreach (($row['comments'] ?? []) as $subjectId => $comment) {
                if ($comment === '' || $comment === null) {
                    continue;
                }
                \App\Models\Tenant\StudentSubjectComment::updateOrCreate(
                    ['student_id' => $studentId, 'subject_id' => $subjectId, 'term_id' => $term->id],
                    ['comment' => $comment]
                );
            }
        }

        return back()->with('status', 'Class entry saved.');
    }

    /**
     * A class teacher's own stamp/signature override for report cards
     * generated from this class arm (user request 2026-07-18: "the school
     * stamp and signature is to be uploaded for all the school's sections
     * and classes but each class teacher can decide to change it"). Blank
     * file inputs leave the existing override untouched; ReportCardService
     * falls back to the school wide default when neither was ever set.
     */
    public function updateArmStampSignature(Request $request, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $school = $this->school($request);
        $arm = ClassArm::findOrFail($request->route('armId'));

        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        abort_if($scopedArmIds !== null && ! $scopedArmIds->contains($arm->id), 404);

        $data = $request->validate([
            'stamp' => ['nullable', 'image', 'max:2048'],
            'signature' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('stamp')) {
            $arm->stamp_path = $images->toWebp($request->file('stamp'), "schools/{$school->id}/arms/{$arm->id}", maxDimension: 500);
        }
        if ($request->hasFile('signature')) {
            $arm->signature_path = $images->toWebp($request->file('signature'), "schools/{$school->id}/arms/{$arm->id}", maxDimension: 500);
        }
        $arm->save();

        return back()->with('status', 'Stamp and signature updated for '.$arm->label().'.');
    }

    /**
     * Clears every class arm's own stamp/signature override in one action
     * (the standing bulk-actions rule: "remember the bulk approach for
     * every action"), so every class falls back to the school wide
     * default set on Branding without having to open each class one at a
     * time. A school wide, admin level decision, gated the same as the
     * review chain/review stage toggle above.
     */
    public function resetArmStampSignatures(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');

        $count = ClassArm::where(function ($q) {
            $q->whereNotNull('stamp_path')->orWhereNotNull('signature_path');
        })->update(['stamp_path' => null, 'signature_path' => null]);

        return back()->with('status', "{$count} class(es) reset to the school's default stamp and signature.");
    }
}
