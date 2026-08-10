<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\ReportCardCustomBlock;
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

    /**
     * Admin toggle for the score entry screen's auto-suggested remark
     * (user request 2026-07-26: "on/off toggle for the score entry remark
     * everywhere it appears"). Off suppresses both the server-prefilled
     * textarea value and the client side live re-suggestion in
     * ExaminationController::index(); a teacher still types their own
     * remark either way, this only controls whether one is suggested for
     * them first. On by default, matching the feature's original behaviour.
     */
    public const SETTING_SUGGESTED_REMARK_ENABLED = 'score_entry_suggested_remark_enabled';

    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    protected function reviewStageEnabled(): bool
    {
        return (bool) Setting::grab(self::SETTING_REVIEW_ENABLED, true);
    }

    /**
     * Default changed to off (user directive 2026-07-27: the subject
     * remark should not show "under the subject scores entry, parent
     * report and under report cards by default", an admin opts it on).
     */
    public static function suggestedRemarkEnabled(): bool
    {
        return (bool) Setting::grab(self::SETTING_SUGGESTED_REMARK_ENABLED, false);
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
            'footerText' => $prefs->footerText(),
            'footerAlign' => $prefs->footerAlign(),
            'schoolMotto' => $school->motto,
            'reviewStageEnabled' => $this->reviewStageEnabled(),
            'suggestedRemarkEnabled' => self::suggestedRemarkEnabled(),
            'reviewChain' => ReportCardReview::reviewChain(),
            'reviewChainRoles' => config('skulos.report_card_review_roles'),
            // The school wide stamp/signature default lives on the School
            // model, actually written by BrandingController::update()
            // (school_info.update), a stricter permission than this page's
            // own report_cards.view (user request 2026-07-22: "option to
            // also upload the school wide stamp and signature should
            // appear here too", so it no longer needs a detour to
            // Branding just to set it).
            'canManageBranding' => app(Rbac::class)->check(Auth::user(), $school, 'school_info.update'),
            'sections' => Section::orderBy('sequence')->get(),
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

    /** Turns the score entry screen's auto-suggested remark on or off school wide. */
    public function updateSuggestedRemark(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $data = $request->validate(['enabled' => ['required', 'boolean']]);

        Setting::put(self::SETTING_SUGGESTED_REMARK_ENABLED, $data['enabled']);

        return back()->with('status', $data['enabled']
            ? 'The score entry auto-suggested remark is on.'
            : 'The score entry auto-suggested remark is off. Teachers will type remarks from scratch.');
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
            // Footer (user request 2026-07-24: "a footer for report
            // card where the school type what they want as the footer
            // but by default picks the motto under school profile...
            // can be place anywhere at the footer"). Blank clears back
            // to "use the motto instead".
            'footer_text' => ['nullable', 'string', 'max:300'],
            'footer_align' => ['nullable', 'string', Rule::in(['left', 'center', 'right'])],
        ]);

        $prefs->setTemplate($data['template']);
        $prefs->setFields($request->input('fields', []));
        $prefs->setFooterText($data['footer_text'] ?? null);
        $prefs->setFooterAlign($data['footer_align'] ?? 'center');

        return back()->with('status', 'Your report card look has been updated.');
    }

    /**
     * A section's own report card signature signer (user request
     * 2026-07-27: "signature signer should be the section head by
     * default... Admin can change the name of the signer in the report
     * card setting for each of the section the school has"). Blank
     * clears back to the Head Teacher/Principal heuristic, Section::
     * signerLabel() already applies.
     */
    public function updateSectionSigner(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $section = Section::findOrFail($request->route('sectionId'));

        $data = $request->validate([
            'signer_name' => ['nullable', 'string', 'max:100'],
        ]);

        $section->update(['signer_name' => $data['signer_name'] ?: null]);

        return back()->with('status', "Report card signer updated for {$section->name}.");
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
            'bodyRows' => $layout->groupIntoRows($layout->effectiveBodyOrder($layout->enabled())),
            'footerText' => $prefs->footerText(),
            'footerAlign' => $prefs->footerAlign(),
            'headerAlign' => $layout->headerAlign(),
            'bodyAlign' => $layout->bodyAlign(),
            'customBlocks' => $this->customBlocksKeyed(),
            'isPreview' => true,
        ]);
    }

    /**
     * A parent/student may reach this WITHOUT report_cards.view (2026-07-26,
     * "there should be a button to view the detailed report card which is
     * the pdf format" on the family-facing Academic Report page), but only
     * for their OWN child's report, and only once it has actually been
     * released (never trust the client, the same ReportCardReview::
     * VISIBLE_TO_FAMILY gate the Academic Report page itself already uses).
     */
    public function show(Request $request, ReportCardService $cards, ReportCardPreferences $prefs, ReportCardLayout $layout): \Illuminate\View\View
    {
        $school = $this->school($request);
        $user = Auth::user();
        $roleSlug = optional($user->roleIn($school))->slug;
        $isFamilyScoped = in_array($roleSlug, config('skulos.family_scoped_roles', []), true);
        if (! $isFamilyScoped) {
            $this->authorizeAction($request, 'report_cards.view');
        }

        $student = Student::with('currentClassArm.schoolClass')->findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = Term::findOrFail($request->route('termId'));
        $reportType = $this->resolveReportType($request);

        if ($isFamilyScoped) {
            $released = ReportCardReview::where('student_id', $student->id)->where('term_id', $term->id)
                ->where('report_type', $reportType)
                ->whereIn('status', ReportCardReview::VISIBLE_TO_FAMILY)->exists();
            abort_unless($released, 404);
        }

        // Narrative Evaluation (2026-07-18, per-term 2026-07-26): overrides
        // the school's own global template choice for this one student's
        // class, for this one term, same reasoning as classEntry() below.
        // Deliberately not applied to a mid-term report: Narrative
        // Evaluation is a comments-only, no-scores template, meaningless
        // for a report built from a subset of scored components.
        $narrativeClass = $reportType === ReportCardReview::REPORT_TYPE_FULL_TERM
            && $student->currentClassArm?->schoolClass?->isNarrativeEvaluationForTerm($term->id);

        return view('school.report-cards.render', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'template' => $narrativeClass ? 'narrative' : $prefs->template(),
            'fields' => $prefs->fields(),
            'data' => $cards->forStudent($student, $term, null, $reportType),
            'gradeBands' => $this->gradeBands(),
            'layoutEnabled' => $layout->enabled(),
            'layoutZones' => $layout->zones(),
            'bodySections' => $layout->effectiveBodyOrder($layout->enabled()),
            'bodyRows' => $layout->groupIntoRows($layout->effectiveBodyOrder($layout->enabled())),
            'footerText' => $prefs->footerText(),
            'footerAlign' => $prefs->footerAlign(),
            'headerAlign' => $layout->headerAlign(),
            'bodyAlign' => $layout->bodyAlign(),
            'customBlocks' => $this->customBlocksKeyed(),
            'isPreview' => false,
        ]);
    }

    /** @return array<string, ReportCardCustomBlock> keyed by ReportCardCustomBlock::bodyKey() */
    protected function customBlocksKeyed(): array
    {
        return ReportCardCustomBlock::all()->keyBy(fn (ReportCardCustomBlock $b) => $b->bodyKey())->all();
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
        // $show always true here on purpose (user report 2026-07-18: "the
        // behavioural section is not showing details or samples"): a
        // field this school currently has switched off (Report settings)
        // would otherwise render its section empty in the designer with
        // no way to see what arranging it would even look like. The real
        // report card (render.blade.php) still uses the school's actual
        // $prefs->shows() and is unaffected by this.
        $bodyLabels = config('skulos.report_card_body_sections', []);
        $bodyHtml = [];
        foreach (array_keys($bodyLabels) as $key) {
            $bodyHtml[$key] = view('school.report-cards._body-'.$key, [
                'template' => $template,
                'show' => fn ($f) => true,
                'data' => $sample,
                'gradeBands' => $this->gradeBands(),
                'isEval' => in_array($template, ['evaluation', 'narrative'], true),
            ])->render();
        }

        // Custom text blocks (user request 2026-07-19: "add option to add
        // text... like address etc"): a school's own free text library,
        // offered alongside the fixed catalog under "custom_{id}" keys.
        $customBlocks = ReportCardCustomBlock::orderByDesc('id')->get();
        foreach ($customBlocks as $block) {
            $bodyLabels[$block->bodyKey()] = $block->label;
            $bodyHtml[$block->bodyKey()] = view('school.report-cards._body-custom', ['customBlock' => $block])->render();
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
            'bodyAlign' => $layout->bodyAlign(),
            'bodyColumns' => $layout->bodyColumns(),
            'customBlocks' => $customBlocks,
        ]);
    }

    /**
     * Custom text blocks live in a small library management block on the
     * Layout Designer, not a separate screen. Content is rendered with
     * {{ }} escaping in the shared _body-custom partial, the same as
     * every other user-authored string in this app, no raw HTML allowed.
     */
    public function storeCustomBlock(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'content' => ['required', 'string', 'max:2000'],
        ]);

        ReportCardCustomBlock::create($data);

        return back()->with('status', 'Custom block added. Drag it onto the page below.');
    }

    public function updateCustomBlock(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $block = ReportCardCustomBlock::findOrFail($request->route('blockId'));
        $data = $request->validate([
            'label' => ['required', 'string', 'max:80'],
            'content' => ['required', 'string', 'max:2000'],
        ]);

        $block->update($data);

        return back()->with('status', 'Custom block updated.');
    }

    public function destroyCustomBlock(Request $request, ReportCardLayout $layout): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $block = ReportCardCustomBlock::findOrFail($request->route('blockId'));
        $key = $block->bodyKey();
        $block->delete();

        // Remove it from wherever it was placed too, an orphaned
        // "custom_5" left in a saved body order would otherwise just
        // silently render nothing forever, confusing to debug later.
        $layout->setBodyOrder(array_values(array_diff($layout->bodyOrder(), [$key])));

        return back()->with('status', 'Custom block removed.');
    }

    public function updateLayout(Request $request, ReportCardLayout $layout): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $validBlocks = array_keys(config('skulos.report_card_layout_blocks', []));

        $data = $request->validate([
            'enabled' => ['nullable', 'boolean'],
            'header' => ['array'],
            'header.*' => [Rule::in($validBlocks)],
            'info' => ['array'],
            'info.*' => [Rule::in($validBlocks)],
            'header_align' => ['array'],
            'header_align.*' => [Rule::in(['left', 'center', 'right'])],
            // Body section keys are either the fixed catalog or a
            // school's own "custom_{id}" text block (user request
            // 2026-07-19), validated authoritatively by
            // ReportCardLayout::setBodyOrder()/setBodyAlign() below
            // rather than duplicated here.
            'body' => ['array'],
            'body.*' => ['string'],
            'body_align' => ['array'],
            'body_align.*' => [Rule::in(['left', 'center', 'right', 'stretch'])],
            'body_columns' => ['array'],
            'body_columns.*' => ['integer', 'min:1', 'max:3'],
        ]);

        $layout->setEnabled((bool) ($data['enabled'] ?? false));
        $layout->setZones(['header' => $data['header'] ?? [], 'info' => $data['info'] ?? []]);
        $layout->setHeaderAlign($data['header_align'] ?? []);
        $layout->setBodyOrder($data['body'] ?? []);
        $layout->setBodyAlign($data['body_align'] ?? []);
        $layout->setBodyColumns($data['body_columns'] ?? []);

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

        // "My classes" shortcut (UX audit finding, 2026-08-10): a class
        // or subject teacher used to see the same full school-wide
        // browse list (every section, every class, ~30 rows on a real
        // school) as an admin, with nothing surfacing their own class
        // first — real daily friction on mobile, a long scroll before
        // reaching anything relevant to their actual job. Admin/wildcard
        // roles get null from classArmIds() (unscoped) and never see
        // this section at all, the full browse list stays their only
        // and correct view.
        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        $myClasses = $scopedArmIds !== null
            ? ClassArm::with(['schoolClass.section', 'arm'])->whereIn('id', $scopedArmIds)->get()
                ->sortBy(fn ($arm) => $arm->orderKey())->values()
            : null;

        return view('school.report-cards.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'sections' => Section::with(['classes' => fn ($q) => $q->orderBy('sequence'), 'classes.arms.arm'])->orderBy('sequence')->get(),
            'myClasses' => $myClasses,
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
     * Which report type (mid-term or full-term) a request is acting on
     * (2026-08-07), the same "read from either a GET query string or a
     * POST body field" shape as resolveTerm() above. Defaults to
     * full-term so every existing form/link that predates this feature
     * (no report_type field at all) keeps behaving exactly as before.
     */
    protected function resolveReportType(Request $request): string
    {
        $reportType = $request->input('report_type', ReportCardReview::REPORT_TYPE_FULL_TERM);

        return in_array($reportType, [ReportCardReview::REPORT_TYPE_MID_TERM, ReportCardReview::REPORT_TYPE_FULL_TERM], true)
            ? $reportType
            : ReportCardReview::REPORT_TYPE_FULL_TERM;
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
        $reportType = $this->resolveReportType($request);

        $armIds = $class->arms->pluck('id');

        // A class/subject teacher opening a class outside their own scope
        // gets a 404, matching every other scoped student/report-card view
        // (audit finding M-1, 2026-07-17).
        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        if ($scopedArmIds !== null) {
            abort_unless($armIds->intersect($scopedArmIds)->isNotEmpty(), 404);
        }

        // Eager loaded and grouped by stream, not just by last name, so a
        // class split into streams (user directive 2026-07-22: "the class
        // stream should be separated here... under the report card page
        // too") stays visually distinguishable on one shared list rather
        // than mixing every stream's students together with no way to
        // tell them apart.
        $students = Student::whereIn('current_class_arm_id', $armIds)->with('currentClassArm.arm')->orderBy('last_name')->get();
        if ($class->arms->count() > 1) {
            $armOrder = $class->arms->sortBy(fn ($arm) => $arm->orderKey())->pluck('id')->flip();
            $students = $students->sortBy(fn ($student) => $armOrder->get($student->current_class_arm_id, 0))->values();
        }
        $reviews = ReportCardReview::whereIn('student_id', $students->pluck('id'))
            ->where('term_id', $term->id)
            ->where('report_type', $reportType)
            ->with('assignedTo')
            ->get()
            ->keyBy('student_id');

        return view('school.report-cards.class-index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'class' => $class,
            'term' => $term,
            'reportType' => $reportType,
            'students' => $students,
            'reviews' => $reviews,
            'staffOptions' => Staff::active()->orderBy('first_name')->get(),
            'canAssign' => app(Rbac::class)->check(Auth::user(), $school, 'report_cards.assign_review'),
            'canRelease' => app(Rbac::class)->check(Auth::user(), $school, 'report_cards.release'),
            'reviewChainRoles' => config('skulos.report_card_review_roles'),
        ]);
    }

    /**
     * Every student in a class, one report card per page, for a single
     * browser print/save-as-PDF pass (user request 2026-07-21: "bulk
     * printing and exporting of report card for a class in PDF or EXCEL —
     * admins and class teachers"). The Excel half of that request is
     * already covered by the existing class broadsheet CSV export
     * (StudentReportsController); this is the PDF half — reuses the exact
     * same per-student render as the single report card
     * (ReportCardController::show()/_sheet.blade.php), just looped, so
     * this can never visually drift from what a single printed card
     * already looks like.
     */
    public function classPrint(Request $request, ReportCardService $cards, ReportCardPreferences $prefs, ReportCardLayout $layout): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.view');
        $school = $this->school($request);
        $class = SchoolClass::with(['section', 'arms'])->findOrFail($request->route('classId'));
        $term = $this->resolveTerm($request);
        $reportType = $this->resolveReportType($request);

        $armIds = $class->arms->pluck('id');
        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        if ($scopedArmIds !== null) {
            abort_unless($armIds->intersect($scopedArmIds)->isNotEmpty(), 404);
        }

        $students = Student::whereIn('current_class_arm_id', $armIds)->orderBy('last_name')->get();
        $template = ($reportType === ReportCardReview::REPORT_TYPE_FULL_TERM && $class->isNarrativeEvaluationForTerm($term->id))
            ? 'narrative' : $prefs->template();

        return view('school.report-cards.class-print', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'class' => $class,
            'term' => $term,
            'report_type' => $reportType,
            'template' => $template,
            'fields' => $prefs->fields(),
            'gradeBands' => $this->gradeBands(),
            'layoutEnabled' => $layout->enabled(),
            'layoutZones' => $layout->zones(),
            'bodySections' => $layout->effectiveBodyOrder($layout->enabled()),
            'bodyRows' => $layout->groupIntoRows($layout->effectiveBodyOrder($layout->enabled())),
            'footerText' => $prefs->footerText(),
            'footerAlign' => $prefs->footerAlign(),
            'headerAlign' => $layout->headerAlign(),
            'bodyAlign' => $layout->bodyAlign(),
            'customBlocks' => $this->customBlocksKeyed(),
            'reportData' => $students->map(fn (Student $student) => $cards->forStudent($student, $term, null, $reportType)),
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
        $reportType = $this->resolveReportType($request);

        $review = ReportCardReview::firstOrCreate(
            ['student_id' => $student->id, 'term_id' => $term->id, 'report_type' => $reportType],
            $this->newReviewAttributes($student->currentClassArm?->schoolClass?->default_reviewer_staff_id)
        );

        return back()->with('status', "{$student->fullName()}'s ".ReportCardReview::reportTypeLabel($reportType)." is ready to review.");
    }

    /** Generate every student in a class at once. */
    public function generateClass(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.create');
        $class = SchoolClass::with('arms')->findOrFail($request->route('classId'));
        $term = $this->resolveTerm($request);
        $reportType = $this->resolveReportType($request);
        $armIds = $class->arms->pluck('id');
        $studentIds = Student::whereIn('current_class_arm_id', $armIds)->pluck('id');

        $created = 0;
        foreach ($studentIds as $studentId) {
            $review = ReportCardReview::firstOrNew(['student_id' => $studentId, 'term_id' => $term->id, 'report_type' => $reportType]);
            if (! $review->exists) {
                $review->fill($this->newReviewAttributes($class->default_reviewer_staff_id));
                $review->save();
                $created++;
            }
        }

        return back()->with('status', "{$created} report card(s) generated.");
    }

    /**
     * A whole-school "generate every class at once, straight to the
     * reviewers" screen (user request 2026-07-23: "add a bulk way for the
     * admin to trigger bulk generate report card for all classes so that
     * it can go to the reviewers for review"), gated on its own
     * report_cards.bulk_generate_all permission rather than the plain
     * report_cards.create every class/subject teacher already holds for
     * their own single class — generating for every class in the school
     * at once is a bigger, institution wide action.
     */
    public function bulkGenerateAllForm(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.bulk_generate_all');
        $school = $this->school($request);

        $terms = Term::with('session')->orderByDesc('starts_on')->get();
        $termId = $request->integer('term_id') ?: null;
        $term = ($termId ? $terms->firstWhere('id', $termId) : null) ?? $terms->firstWhere('is_current', true) ?? $terms->first();
        $reportType = $this->resolveReportType($request);

        $classes = SchoolClass::with(['section', 'arms'])->get()
            ->sortBy(fn (SchoolClass $c) => sprintf('%05d-%05d', $c->section?->sequence ?? 0, $c->sequence ?? 0))
            ->map(function (SchoolClass $class) use ($term, $reportType) {
                $armIds = $class->arms->pluck('id');
                $studentIds = Student::whereIn('current_class_arm_id', $armIds)->pluck('id');
                $alreadyGenerated = $term ? ReportCardReview::where('term_id', $term->id)->where('report_type', $reportType)->whereIn('student_id', $studentIds)->count() : 0;

                return [
                    'name' => $class->name,
                    'section' => $class->section?->name,
                    'student_count' => $studentIds->count(),
                    'already_generated' => $alreadyGenerated,
                ];
            })
            ->filter(fn ($row) => $row['student_count'] > 0)
            ->values();

        return view('school.report-cards.bulk-generate', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'terms' => $terms,
            'term' => $term,
            'reportType' => $reportType,
            'classes' => $classes,
        ]);
    }

    public function bulkGenerateAll(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.bulk_generate_all');
        $term = $this->resolveTerm($request);
        $reportType = $this->resolveReportType($request);

        $created = 0;
        $classesCovered = 0;
        foreach (SchoolClass::with('arms')->get() as $class) {
            $armIds = $class->arms->pluck('id');
            $studentIds = Student::whereIn('current_class_arm_id', $armIds)->pluck('id');
            if ($studentIds->isEmpty()) {
                continue;
            }
            $classesCovered++;

            foreach ($studentIds as $studentId) {
                $review = ReportCardReview::firstOrNew(['student_id' => $studentId, 'term_id' => $term->id, 'report_type' => $reportType]);
                if (! $review->exists) {
                    $review->fill($this->newReviewAttributes($class->default_reviewer_staff_id));
                    $review->save();
                    $created++;
                }
            }
        }

        return redirect()->route('school.report-cards.pending-review', ['school' => $this->school($request)->slug, 'report_type' => $reportType])
            ->with('status', "{$created} ".ReportCardReview::reportTypeLabel($reportType)." card(s) generated across {$classesCovered} class(es), ready for review.");
    }

    /**
     * Bulk Release, the same shape as Bulk Generate above (user request
     * 2026-07-26: "same way we have bulk generate report cards, lets add
     * a bulk release all report card (approved only, non approved, all
     * genereated report cards)"). Only ever acts on a review row that
     * already exists for this term (a report card that was never
     * generated has nothing to release), and never re-releases one
     * that is already released/locked/archived, so released_at is
     * never silently overwritten by re-running this.
     */
    public function bulkReleaseAllForm(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'report_cards.release');
        $school = $this->school($request);

        $terms = Term::with('session')->orderByDesc('starts_on')->get();
        $termId = $request->integer('term_id') ?: null;
        $term = ($termId ? $terms->firstWhere('id', $termId) : null) ?? $terms->firstWhere('is_current', true) ?? $terms->first();
        $reportType = $this->resolveReportType($request);

        $counts = ['approved' => 0, 'non_approved' => 0, 'already_released' => 0];
        if ($term) {
            $reviews = ReportCardReview::where('term_id', $term->id)->where('report_type', $reportType)->get(['status']);
            foreach ($reviews as $review) {
                if (in_array($review->status, ReportCardReview::VISIBLE_TO_FAMILY, true)) {
                    $counts['already_released']++;
                } elseif ($review->status === ReportCardReview::STATUS_APPROVED) {
                    $counts['approved']++;
                } else {
                    $counts['non_approved']++;
                }
            }
        }

        return view('school.report-cards.bulk-release', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'terms' => $terms,
            'term' => $term,
            'reportType' => $reportType,
            'counts' => $counts,
        ]);
    }

    public function bulkReleaseAll(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.release');
        $term = $this->resolveTerm($request);
        $reportType = $this->resolveReportType($request);
        $data = $request->validate(['scope' => ['required', Rule::in(['approved', 'non_approved', 'all'])]]);

        $query = ReportCardReview::where('term_id', $term->id)
            ->where('report_type', $reportType)
            ->whereNotIn('status', ReportCardReview::VISIBLE_TO_FAMILY);

        if ($data['scope'] === 'approved') {
            $query->where('status', ReportCardReview::STATUS_APPROVED);
        } elseif ($data['scope'] === 'non_approved') {
            $query->where('status', '!=', ReportCardReview::STATUS_APPROVED);
        }
        // 'all' releases every not-yet-released review row for this term,
        // approved or not, no further filter beyond the base query above.

        $studentIds = $query->pluck('student_id');
        $count = $query->update(['status' => ReportCardReview::STATUS_RELEASED, 'released_at' => now()]);

        // Same family notification release() sends, just per student here
        // (user report 2026-07-27: "parent did not get notification for
        // release report card"), a bulk release previously told nobody at
        // all despite being the more commonly used release path.
        foreach (Student::whereIn('id', $studentIds)->get() as $student) {
            $this->notifyFamilyOfRelease($student, $term, $reportType);
        }

        return redirect()->route('school.report-cards.bulk-release', ['school' => $this->school($request)->slug, 'term_id' => $term->id, 'report_type' => $reportType])
            ->with('status', "{$count} ".ReportCardReview::reportTypeLabel($reportType)." card(s) released for {$term->name}.");
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
        $reportType = $this->resolveReportType($request);

        $data = $request->validate([
            'assigned_to_staff_id' => ['required', 'exists:tenant.staff,id'],
            'notes' => ['required', 'string', 'max:1000'],
        ], [
            'notes.required' => 'A comment is required when sending a report card back for correction.',
        ]);

        $review = ReportCardReview::firstOrNew(['student_id' => $student->id, 'term_id' => $term->id, 'report_type' => $reportType]);
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

    /**
     * Bulk version of sendBack(), one shared reason applied to every
     * selected report card, each sent back to its own currently assigned
     * staff member (mirroring the single-row UI, which sends a card back
     * to whoever it is already assigned to, not a newly chosen assignee).
     * Skips (does not fail the whole batch on) a card with no assignee.
     */
    public function bulkSendBack(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $school = $this->school($request);
        $term = $this->resolveTerm($request);
        $reportType = $this->resolveReportType($request);

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['exists:tenant.students,id'],
            'notes' => ['required', 'string', 'max:1000'],
        ], [
            'notes.required' => 'A comment is required when sending report cards back for correction.',
        ]);

        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        $studentIds = $scopedArmIds !== null
            ? Student::whereIn('id', $data['student_ids'])->whereIn('current_class_arm_id', $scopedArmIds)->pluck('id')
            : collect($data['student_ids']);

        $reviews = ReportCardReview::whereIn('student_id', $studentIds)->where('term_id', $term->id)->where('report_type', $reportType)->get()
            ->keyBy('student_id');

        $sent = 0;
        $skipped = 0;
        foreach ($studentIds as $studentId) {
            $review = $reviews->get($studentId) ?? ReportCardReview::firstOrNew(['student_id' => $studentId, 'term_id' => $term->id, 'report_type' => $reportType]);

            if (! $review->assigned_to_staff_id) {
                $skipped++;

                continue;
            }

            $review->fill([
                'status' => ReportCardReview::STATUS_NEEDS_CORRECTION,
                'review_stage' => 0,
                'notes' => $data['notes'],
            ])->save();
            $sent++;
        }

        $message = "{$sent} report card(s) sent back for correction.";
        if ($skipped) {
            $message .= " Skipped {$skipped} with no assigned staff member.";
        }

        return back()->with($skipped && ! $sent ? 'error' : 'status', $message);
    }

    /** Single assign: who is reviewing this one report card. */
    public function assignReview(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $student = Student::findOrFail($request->route('studentId'));
        $this->assertStudentVisible($request, $student);
        $term = $this->resolveTerm($request);
        $reportType = $this->resolveReportType($request);

        $data = $request->validate(['assigned_to_staff_id' => ['nullable', 'exists:tenant.staff,id']]);

        $review = ReportCardReview::firstOrNew(['student_id' => $student->id, 'term_id' => $term->id, 'report_type' => $reportType]);
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
        $reportType = $this->resolveReportType($request);

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
            $review = ReportCardReview::firstOrNew(['student_id' => $studentId, 'term_id' => $term->id, 'report_type' => $reportType]);
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
        $reportType = $this->resolveReportType($request);

        $review = ReportCardReview::firstOrNew(['student_id' => $student->id, 'term_id' => $term->id, 'report_type' => $reportType]);

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

    /** Bulk version of approve(), same list-screen bulk-action pattern used across the app. */
    public function bulkApprove(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $school = $this->school($request);
        $term = $this->resolveTerm($request);
        $reportType = $this->resolveReportType($request);

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['exists:tenant.students,id'],
        ]);

        // A scoped teacher's id list is narrowed to their own classes, same
        // convention as bulkAssignReview() above.
        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        $studentIds = $scopedArmIds !== null
            ? Student::whereIn('id', $data['student_ids'])->whereIn('current_class_arm_id', $scopedArmIds)->pluck('id')
            : collect($data['student_ids']);

        $students = Student::whereIn('id', $studentIds)->get();

        $count = 0;
        foreach ($students as $student) {
            $review = ReportCardReview::firstOrNew(['student_id' => $student->id, 'term_id' => $term->id, 'report_type' => $reportType]);

            if ($review->isFinalStage()) {
                $review->status = ReportCardReview::STATUS_APPROVED;
            } else {
                $review->review_stage++;
                $review->status = ReportCardReview::STATUS_PENDING_REVIEW;
            }
            $review->save();
            $count++;
        }

        return back()->with('status', "{$count} report card(s) approved or moved to the next review stage.");
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
        $reportType = $this->resolveReportType($request);

        $data = $request->validate([
            'promotion_recommendation' => ['required', Rule::in([
                ReportCardReview::PROMOTION_PROMOTE, ReportCardReview::PROMOTION_REPEAT, ReportCardReview::PROMOTION_CONDITIONAL,
            ])],
            'promotion_reason' => ['nullable', 'string', 'max:1000'],
        ]);

        $review = ReportCardReview::firstOrNew(['student_id' => $student->id, 'term_id' => $term->id, 'report_type' => $reportType]);
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
        $reportType = $this->resolveReportType($request);

        $review = ReportCardReview::firstOrNew(['student_id' => $student->id, 'term_id' => $term->id, 'report_type' => $reportType]);
        $review->status = ReportCardReview::STATUS_RELEASED;
        $review->released_at = now();
        $review->save();

        $this->notifyFamilyOfRelease($student, $term, $reportType);

        return back()->with('status', "{$student->fullName()}'s report card released.");
    }

    /**
     * A parent (every linked guardian) and the student's own login, if
     * either has one, told their report card is ready to view (user
     * report 2026-07-27: "parent did not get notification for release
     * report card"). Was never wired up at all on any of the three
     * release paths (this single one, and both bulk ones below),
     * release only ever changed the underlying ReportCardReview status,
     * nothing told the family it had happened.
     */
    protected function notifyFamilyOfRelease(Student $student, Term $term, string $reportType = ReportCardReview::REPORT_TYPE_FULL_TERM): void
    {
        $userIds = \App\Models\Tenant\StudentGuardian::where('student_id', $student->id)
            ->whereNotNull('guardian_user_id')->pluck('guardian_user_id');
        if ($student->user_id) {
            $userIds->push($student->user_id);
        }

        $label = ReportCardReview::reportTypeLabel($reportType);
        foreach ($userIds->unique() as $userId) {
            \App\Models\Tenant\Notification::create([
                'user_id' => $userId,
                'category' => 'report_card_released',
                'type' => \App\Models\Tenant\Notification::typeFor('report_card_released'),
                'title' => 'Report card released',
                'body' => "{$student->fullName()}'s {$term->name} {$label} is now available.",
            ]);
        }
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
        $reportType = $this->resolveReportType($request);

        $review = ReportCardReview::where('student_id', $student->id)->where('term_id', $term->id)->where('report_type', $reportType)->firstOrFail();
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
        $reportType = $this->resolveReportType($request);

        $review = ReportCardReview::where('student_id', $student->id)->where('term_id', $term->id)->where('report_type', $reportType)->firstOrFail();
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
        $reportType = $this->resolveReportType($request);

        // Paginated (Dbase recommendation standing rule: "paginate every
        // list screen"), found still missing here 2026-07-24 once the
        // bulk generate feature could realistically put an entire
        // school's worth of report cards, hundreds of rows, into this
        // one unpaginated table at once. The page's own client side
        // filter/sort/select-all still work exactly as before, just
        // scoped to whichever page is currently loaded, the same
        // tradeoff every other paginated list screen in the app already
        // makes.
        $reviews = ReportCardReview::where('term_id', $term->id)
            ->where('report_type', $reportType)
            ->whereIn('status', [ReportCardReview::STATUS_PENDING_REVIEW, ReportCardReview::STATUS_NEEDS_CORRECTION])
            ->with(['student.currentClassArm.schoolClass', 'assignedTo'])
            ->orderBy('updated_at')
            ->paginate(30)
            ->withQueryString();

        return view('school.report-cards.pending-review', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'term' => $term,
            'reportType' => $reportType,
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
        $reportType = $this->resolveReportType($request);

        $review = ReportCardReview::where('student_id', $student->id)->where('term_id', $term->id)->where('report_type', $reportType)->firstOrFail();
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
     * Bulk version of nudge(), same list-screen bulk-action pattern used
     * across the app. Mirrors nudge()'s own two guards per row, skipping
     * (not failing the whole batch on) a review with no assigned staff or
     * an assigned staff member with no login.
     */
    public function bulkNudge(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.assign_review');
        $school = $this->school($request);
        $term = $this->currentTermOrAbort();
        $reportType = $this->resolveReportType($request);

        $data = $request->validate([
            'student_ids' => ['required', 'array', 'min:1'],
            'student_ids.*' => ['exists:tenant.students,id'],
        ]);

        $scopedArmIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        $studentIds = $scopedArmIds !== null
            ? Student::whereIn('id', $data['student_ids'])->whereIn('current_class_arm_id', $scopedArmIds)->pluck('id')
            : collect($data['student_ids']);

        $reviews = ReportCardReview::whereIn('student_id', $studentIds)->where('term_id', $term->id)->where('report_type', $reportType)->with('assignedTo', 'student')->get();

        $nudged = 0;
        $skipped = 0;
        foreach ($reviews as $review) {
            $staff = $review->assignedTo;
            if (! $staff || ! $staff->user_id) {
                $skipped++;

                continue;
            }

            \App\Models\Tenant\Notification::create([
                'user_id' => $staff->user_id,
                'title' => 'Report card awaiting your review',
                'body' => "{$review->student->fullName()}'s report card is waiting on you to review, approve, or send back.",
                'category' => 'report_card_review',
                'type' => \App\Models\Tenant\Notification::typeFor('report_card_review'),
            ]);
            $nudged++;
        }

        $message = "{$nudged} reviewer(s) nudged.";
        if ($skipped) {
            $message .= " Skipped {$skipped} report card(s) with no assigned staff or no login.";
        }

        return back()->with($skipped && ! $nudged ? 'error' : 'status', $message);
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
        // Narrative Evaluation (2026-07-18, per-term 2026-07-26): a class
        // toggled narrative for THIS term always uses the comment based
        // template here, regardless of the school's own global template
        // choice, so a school can mix a scored Primary/Secondary with a
        // narrative Creche/Reception rather than the template being one
        // all-or-nothing school wide setting, and can toggle differently
        // term to term without rewriting a past term's report cards.
        $template = $class->isNarrativeEvaluationForTerm($term->id) ? 'narrative' : $prefs->template();
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
     * A CSV round trip for class entry (user report 2026-07-22: "template
     * download and upload is missing here for Narrative Evaluation
     * classes. One should be able to download the template with all
     * learners details and fill necessary areas"). Pre-filled with every
     * student's CURRENT values, not blank, so filling it in is "correct
     * what's missing", not "retype everything from scratch". Columns
     * mirror the on-screen table exactly (traits, then one column per
     * subject comment, then remarks), matched back on upload by admission
     * number, the school's own stable per-student identifier.
     */
    public function classEntryTemplate(Request $request): \Symfony\Component\HttpFoundation\StreamedResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $school = $this->school($request);
        $user = Auth::user();

        $class = SchoolClass::with(['arms'])->findOrFail($request->route('classId'));
        $term = $this->resolveTerm($request);
        $armIds = $class->arms->pluck('id');
        $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);
        if ($scopedArmIds !== null) {
            abort_unless($armIds->intersect($scopedArmIds)->isNotEmpty(), 404);
        }

        $students = Student::whereIn('current_class_arm_id', $armIds)->active()->orderBy('last_name')->get();
        $traits = \App\Models\Tenant\BehaviouralTrait::active()->orderBy('sequence')->get();
        $prefs = app(ReportCardPreferences::class);
        $narrativeClass = $class->isNarrativeEvaluationForTerm($term->id);
        $subjects = ($narrativeClass || $prefs->shows('subject_comments'))
            ? \App\Models\Tenant\Subject::whereIn('id', \App\Models\Tenant\TeachingAssignment::whereIn('class_arm_id', $armIds)->pluck('subject_id')->unique())->orderBy('name')->get()
            : collect();
        $canEnterPrincipalRemark = app(Rbac::class)->check($user, $school, 'report_cards.release');

        $remarks = \App\Models\Tenant\StudentTermRemark::whereIn('student_id', $students->pluck('id'))->where('term_id', $term->id)->get()->keyBy('student_id');
        $ratings = \App\Models\Tenant\StudentBehaviouralRating::whereIn('student_id', $students->pluck('id'))->where('term_id', $term->id)->get()->groupBy('student_id');
        $comments = \App\Models\Tenant\StudentSubjectComment::whereIn('student_id', $students->pluck('id'))->where('term_id', $term->id)->get();

        return response()->streamDownload(function () use ($students, $traits, $subjects, $remarks, $ratings, $comments, $canEnterPrincipalRemark) {
            $out = fopen('php://output', 'w');
            $header = ['Admission Number', 'Student Name', 'Height', 'Weight', 'Club'];
            foreach ($traits as $trait) {
                $header[] = $trait->name;
            }
            foreach ($subjects as $subject) {
                $header[] = $subject->name.' comment';
            }
            $header[] = 'Class Teacher Remark';
            if ($canEnterPrincipalRemark) {
                $header[] = 'Head of School Remark';
            }
            fputcsv($out, $header);

            foreach ($students as $student) {
                $remark = $remarks->get($student->id);
                $studentRatings = ($ratings->get($student->id) ?? collect())->keyBy('behavioural_trait_id');
                $row = [$student->admission_number, $student->fullName(), $remark?->height, $remark?->weight, $remark?->club];
                foreach ($traits as $trait) {
                    $row[] = $studentRatings->get($trait->id)?->value;
                }
                foreach ($subjects as $subject) {
                    $row[] = $comments->first(fn ($c) => $c->student_id === $student->id && $c->subject_id === $subject->id)?->comment;
                }
                $row[] = $remark?->class_teacher_remark;
                if ($canEnterPrincipalRemark) {
                    $row[] = $remark?->principal_remark;
                }
                fputcsv($out, $row);
            }
            fclose($out);
        // A class name can legitimately contain "/" (e.g. "Creche/
        // Reception"), which Symfony's Content-Disposition filename
        // rejects outright, not just renders oddly.
        }, 'class-entry-'.\Illuminate\Support\Str::slug($class->name).'.csv');
    }

    public function classEntryUpload(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'report_cards.update');
        $school = $this->school($request);
        $user = Auth::user();

        $class = SchoolClass::with(['arms'])->findOrFail($request->route('classId'));
        $term = $this->resolveTerm($request);
        $armIds = $class->arms->pluck('id');
        $scopedArmIds = app(TeacherScope::class)->classArmIds($user, $school);
        if ($scopedArmIds !== null) {
            abort_unless($armIds->intersect($scopedArmIds)->isNotEmpty(), 404);
        }
        $data = $request->validate(['file' => ['required', 'file', 'mimes:csv,txt']]);

        $students = Student::whereIn('current_class_arm_id', $armIds)->active()->get()->keyBy('admission_number');
        $traits = \App\Models\Tenant\BehaviouralTrait::active()->orderBy('sequence')->get();
        $prefs = app(ReportCardPreferences::class);
        $narrativeClass = $class->isNarrativeEvaluationForTerm($term->id);
        $subjects = ($narrativeClass || $prefs->shows('subject_comments'))
            ? \App\Models\Tenant\Subject::whereIn('id', \App\Models\Tenant\TeachingAssignment::whereIn('class_arm_id', $armIds)->pluck('subject_id')->unique())->orderBy('name')->get()
            : collect();
        $canEnterPrincipalRemark = app(Rbac::class)->check($user, $school, 'report_cards.release');
        $remarkScale = config('skulos.behavioural_trait_remark_scale', []);

        $handle = fopen($data['file']->getRealPath(), 'r');
        fgetcsv($handle); // header row, columns are positional per classEntryTemplate() above
        $updated = 0;
        $skipped = 0;
        while (($line = fgetcsv($handle)) !== false) {
            $cursor = 0;
            $admissionNumber = trim((string) ($line[$cursor++] ?? ''));
            $cursor++; // Student Name, informational only, not written back
            $student = $students->get($admissionNumber);
            if (! $student) {
                $skipped++;

                continue;
            }

            $remarkData = ['height' => $line[$cursor++] ?? null, 'weight' => $line[$cursor++] ?? null, 'club' => $line[$cursor++] ?? null];
            \App\Models\Tenant\StudentTermRemark::updateOrCreate(
                ['student_id' => $student->id, 'term_id' => $term->id],
                array_filter($remarkData, fn ($v) => $v !== null && $v !== '') + ($canEnterPrincipalRemark ? [] : [])
            );

            foreach ($traits as $trait) {
                $value = trim((string) ($line[$cursor++] ?? ''));
                if ($value === '') {
                    continue;
                }
                if ($trait->type === 'score' && ! is_numeric($value)) {
                    continue;
                }
                if ($trait->type !== 'score' && ! in_array($value, $remarkScale, true)) {
                    continue;
                }
                \App\Models\Tenant\StudentBehaviouralRating::updateOrCreate(
                    ['student_id' => $student->id, 'term_id' => $term->id, 'behavioural_trait_id' => $trait->id],
                    ['value' => $value]
                );
            }

            foreach ($subjects as $subject) {
                $comment = trim((string) ($line[$cursor++] ?? ''));
                if ($comment === '') {
                    continue;
                }
                \App\Models\Tenant\StudentSubjectComment::updateOrCreate(
                    ['student_id' => $student->id, 'subject_id' => $subject->id, 'term_id' => $term->id],
                    ['comment' => $comment]
                );
            }

            $classTeacherRemark = trim((string) ($line[$cursor++] ?? ''));
            if ($classTeacherRemark !== '') {
                \App\Models\Tenant\StudentTermRemark::where('student_id', $student->id)->where('term_id', $term->id)
                    ->update(['class_teacher_remark' => $classTeacherRemark]);
            }
            if ($canEnterPrincipalRemark) {
                $principalRemark = trim((string) ($line[$cursor++] ?? ''));
                if ($principalRemark !== '') {
                    \App\Models\Tenant\StudentTermRemark::where('student_id', $student->id)->where('term_id', $term->id)
                        ->update(['principal_remark' => $principalRemark]);
                }
            }

            $updated++;
        }
        fclose($handle);

        return back()->with('status', "Class entry uploaded for {$updated} student(s)".($skipped > 0 ? ", {$skipped} row(s) skipped (unrecognized admission number)." : '.'));
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
