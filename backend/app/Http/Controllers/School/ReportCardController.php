<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ReportCardReview;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\Term;
use App\Services\Rbac\Rbac;
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

    public function preview(Request $request, ReportCardService $cards, ReportCardPreferences $prefs): \Illuminate\View\View
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
            'isPreview' => true,
        ]);
    }

    public function show(Request $request, ReportCardService $cards, ReportCardPreferences $prefs): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);
        $student = Student::findOrFail($request->route('studentId'));
        $term = Term::findOrFail($request->route('termId'));

        return view('school.report-cards.render', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'template' => $prefs->template(),
            'fields' => $prefs->fields(),
            'data' => $cards->forStudent($student, $term),
            'isPreview' => false,
        ]);
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
        ]);
    }

    protected function currentTermOrAbort(): Term
    {
        $term = Term::current()->first();
        abort_unless($term, 404, 'There is no current term set. Set one under Settings > Sessions & Terms first.');

        return $term;
    }

    /**
     * One class's students with their report card review status for the
     * current term (user request 2026-07-11: "from each one can either
     * view a report card of a student, generate report card, review it,
     * send it back... or single/bulk assign some class report to a
     * staff for review").
     */
    public function classIndex(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);
        $class = SchoolClass::with(['section', 'arms'])->findOrFail($request->route('classId'));
        $term = $this->currentTermOrAbort();

        $armIds = $class->arms->pluck('id');
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
        $term = $this->currentTermOrAbort();

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
        $term = $this->currentTermOrAbort();
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
        $term = $this->currentTermOrAbort();

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
        $term = $this->currentTermOrAbort();

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
        $term = $this->currentTermOrAbort();

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['exists:tenant.students,id'],
            'assigned_to_staff_id' => ['required', 'exists:tenant.staff,id'],
        ]);

        $count = 0;
        foreach ($data['student_ids'] as $studentId) {
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
        $term = $this->currentTermOrAbort();

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
        $term = $this->currentTermOrAbort();

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
        $term = $this->currentTermOrAbort();

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
        $term = $this->currentTermOrAbort();

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
        $term = $this->currentTermOrAbort();

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
}
