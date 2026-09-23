<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Student;
use App\Models\Tenant\Staff;
use App\Models\Tenant\CalendarEvent;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolSectionBranding;
use App\Models\Tenant\Term;
use App\Services\Dashboard\DashboardPreferences;
use App\Services\Dashboard\RoleDashboard;
use App\Services\Media\ImageProcessingService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * The per-school portal: a branded welcome/login page, a dashboard with the
 * user's notification feed and quick stats, and school branding settings.
 * The active school is resolved by the tenant middleware and read from
 * request attributes.
 */
class SchoolPortalController extends Controller
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
     * The school's root URL. A signed in member goes straight to their
     * dashboard; everyone else goes straight to the sign in page (user
     * request 2026-07-09: a separate low content "click here to sign in"
     * page in between was confusing, not a real landing page).
     */
    public function welcome(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);
        $user = Auth::user();

        if ($user && ($user->is_super_admin || $user->isMemberOf($school))) {
            return redirect()->route('school.dashboard', ['school' => $school->slug]);
        }

        return redirect()->route('school.login', ['school' => $school->slug]);
    }

    /** Dashboard with the signed in user's notification feed and role specific figures. */
    public function dashboard(Request $request, DashboardPreferences $prefs, RoleDashboard $roleDashboard): \Illuminate\View\View
    {
        $school = $this->school($request);
        $feed = Notification::feedFor(Auth::id());

        $setupComplete = \App\Models\Tenant\AcademicSession::exists()
            && \App\Models\Tenant\Section::whereHas('classes')->exists()
            && \App\Models\Tenant\House::exists()
            && \App\Models\Tenant\AssessmentComponent::exists();

        return view('school.dashboard', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'feed' => $feed,
            'role' => $roleDashboard->build(Auth::user(), $school, $feed['unread']),
            'upcoming' => CalendarEvent::upcoming()->limit(5)->get(),
            // The "Public holidays" card used to just repeat whichever
            // holidays already happened to appear in "Upcoming on the
            // calendar" right above it (user report 2026-07-16: "similar
            // data under both Upcoming on the calendar and Public
            // holidays, let's retain only Upcoming on the calendar,
            // another data can be used to replace the Public holidays
            // card section"). Replaced with a genuinely different lens on
            // the same calendar: this term's own Beginning/Mid Term
            // Break/End of Term milestones, past and future both (the
            // "Upcoming" feed only ever shows what's still ahead), so a
            // user can see at a glance where the school currently sits
            // within its own term.
            'termKeyDates' => $this->termKeyDates(),
            // The dashboard "Announcements" widget was every published
            // Announcement school wide, no per-user state at all, always
            // the same latest 3 for everyone (user report 2026-07-16: "IT
            // SHOULD NOT BE SHOWING ALL THE ANNOUNCEMENTS... THE USER CAN
            // DECIDE TO DISMISS IT BUT IT RELOADS WHEN USER LOGIN AGAIN
            // AND THE USER ONLY SEE NEW ONE"). Reused the exact same
            // per-user Notification.read_at tracking the broadcast pop-up
            // already has (increment 1), scoped to category='announcement'
            // specifically so a quiet Announcement and an acknowledge-
            // gated Broadcast pop-up never double up on the same
            // dismissal state; dismissing here posts to the same existing
            // school.notifications.read route.
            'announcementNotifications' => Notification::forUser(Auth::id())
                ->where('category', 'announcement')->whereNull('read_at')
                ->with('announcement')->latest()->limit(10)->get(),
            'layout' => $prefs->layout(),
            'widgets' => $prefs->widgets(),
            'setupComplete' => $setupComplete,
        ]);
    }

    /**
     * The current term's own Beginning of Term/Mid Term Break/End of Term
     * dates, each with a relative status (done, today, or "in N days"), so
     * the dashboard "Public holidays" slot could be replaced with
     * something that isn't just a repeat of the calendar widget beside it.
     *
     * @return array{term: ?Term, items: array<int, array{title: string, starts_on: ?\Illuminate\Support\Carbon, ends_on: ?\Illuminate\Support\Carbon, status: string}>}
     */
    protected function termKeyDates(): array
    {
        $term = Term::current()->first();
        if (! $term) {
            return ['term' => null, 'items' => []];
        }

        $events = CalendarEvent::where('is_default', true)
            ->whereIn('title', ['Beginning of Term', 'Mid Term Break', 'End of Term'])
            ->get()
            ->keyBy('title');

        $items = [];
        foreach (['Beginning of Term', 'Mid Term Break', 'End of Term'] as $title) {
            $event = $events->get($title);
            $items[] = [
                'title' => $title,
                'starts_on' => $event?->starts_on,
                'ends_on' => $event?->ends_on,
                'status' => $this->relativeDateStatus($event?->ends_on ?? $event?->starts_on),
            ];
        }

        return ['term' => $term, 'items' => $items];
    }

    protected function relativeDateStatus(?\Illuminate\Support\Carbon $date): string
    {
        if (! $date) {
            return 'not_set';
        }
        $days = now()->startOfDay()->diffInDays($date->copy()->startOfDay(), false);
        if ($days < 0) {
            return 'done';
        }

        return $days === 0 ? 'today' : "in {$days} day".($days === 1 ? '' : 's');
    }

    /** Dashboard appearance settings: layout template + widget visibility. */
    public function editDashboard(Request $request, DashboardPreferences $prefs): \Illuminate\View\View
    {
        $school = $this->school($request);

        return view('school.settings-dashboard', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'currentLayout' => $prefs->layout(),
            'widgets' => $prefs->widgets(),
        ]);
    }

    public function updateDashboard(Request $request, DashboardPreferences $prefs): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'layout' => ['required', 'string', Rule::in(array_keys(config('skulos.dashboard_layouts')))],
            'widgets' => ['array'],
        ]);

        $prefs->setLayout($data['layout']);
        $prefs->setWidgets($request->input('widgets', []));

        return back()->with('status', 'Your dashboard look has been updated.');
    }

    /**
     * The header bell dropdown and dashboard widget both mark a
     * notification read via fetch() rather than a plain form submit (user
     * report 2026-07-21: "CLICKING ON THE MARK READ BUTTON DOES NOT NEED
     * TO RELOAD THE PAGE"), so this responds with JSON for that case and
     * falls back to the original redirect for any plain-form caller.
     */
    public function markNotificationRead(Request $request, string $school, int $id): \Illuminate\Http\RedirectResponse|\Illuminate\Http\JsonResponse
    {
        $n = Notification::forUser(Auth::id())->findOrFail($id);
        $n->markRead();

        if ($request->wantsJson()) {
            return response()->json(['status' => 'ok']);
        }

        return back();
    }

    /**
     * School branding settings form. Gated on school_info.update (found
     * while building the Setup Wizard 2026-07-12: this screen had no RBAC
     * check at all beyond plain school membership, so any parent or
     * student login could repaint the school's brand color and login
     * page). Not previously enforced anywhere, fixed here rather than
     * left as a known gap since the wizard step now depends on this exact
     * permission to decide whether to show the form.
     */
    public function editBranding(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.update');
        $school = $this->school($request);
        $sections = Section::with('branding')->orderBy('sequence')->get();

        return view('school.settings-branding', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'chartStyle' => \App\Models\Tenant\Setting::grab('chart_style', config('skulos.default_chart_style', 'line')),
            'sections' => $sections,
            'showAllSectionLogos' => (bool) $school->show_all_section_logos_on_login,
        ]);
    }

    public function updateBranding(Request $request, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.update');
        $school = $this->school($request);
        $sectionIds = Section::pluck('id')->all();

        $data = $request->validate([
            'brand_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'welcome_headline' => ['nullable', 'string', 'max:120'],
            'welcome_message' => ['nullable', 'string', 'max:400'],
            'login_layout' => ['nullable', Rule::in(array_keys(config('skulos.login_layouts')))],
            'login_box_side' => ['nullable', Rule::in(['left', 'right'])],
            'show_all_section_logos_on_login' => ['nullable', 'boolean'],
            'logo' => ['nullable', 'image', 'max:2048'],
            // Which sections a freshly uploaded logo/colour applies to
            // (user request 2026-09-09: "ask if its for all sections in
            // the school or ask them to tick a box beside each
            // section"). Defaults to the whole school, so a school that
            // never touches this keeps today's single-logo behaviour
            // exactly. Validated against this school's own real section
            // ids via Rule::in() rather than an `exists:` rule --
            // `exists:` defaults to the PLATFORM connection, not the
            // tenant one, a known gotcha in this codebase (see CLAUDE.md).
            'logo_scope' => ['nullable', Rule::in(['school', 'sections'])],
            'logo_sections' => ['nullable', 'array'],
            'logo_sections.*' => [Rule::in($sectionIds)],
            'logo_groups' => ['nullable', 'array'],
            'logo_groups.*.file' => ['nullable', 'image', 'max:2048'],
            'logo_groups.*.sections' => ['nullable', 'array'],
            'logo_groups.*.sections.*' => [Rule::in($sectionIds)],
            'brand_color_scope' => ['nullable', Rule::in(['school', 'sections'])],
            'brand_color_sections' => ['nullable', 'array'],
            'brand_color_sections.*' => [Rule::in($sectionIds)],
            // The school-wide default stamp/signature every report card
            // falls back to when a class teacher never uploaded their own
            // (user request 2026-07-18: "uploaded for all the school's
            // sections and classes but each class teacher can decide to
            // change it").
            'stamp' => ['nullable', 'image', 'max:2048'],
            'signature' => ['nullable', 'image', 'max:2048'],
            'chart_style' => ['nullable', Rule::in(array_keys(config('skulos.chart_styles')))],
        ]);

        if (isset($data['chart_style'])) {
            \App\Models\Tenant\Setting::put('chart_style', $data['chart_style']);
        }

        // A scope of "sections" with nothing ticked is a genuine mistake,
        // not a signal to fall back to the whole school -- silently
        // re-interpreting it that way would repaint every section's
        // logo/colour the moment an admin forgot to tick anything. Not
        // gated on hasFile('logo') any more (user report 2026-09-22: "i
        // change the school logo to apply to only secondary section...
        // it did not save" -- they were reassigning the ALREADY
        // uploaded school logo to a section, not choosing a new file,
        // so the old file-only guard silently did nothing at all).
        if (($data['logo_scope'] ?? 'school') === 'sections' && empty($data['logo_sections'])) {
            return back()->withErrors(['logo_sections' => 'Tick at least one section, or choose "Whole school" instead.'])->withInput();
        }
        if (! empty($data['brand_color']) && ($data['brand_color_scope'] ?? 'school') === 'sections' && empty($data['brand_color_sections'])) {
            return back()->withErrors(['brand_color_sections' => 'Tick at least one section, or choose "Whole school" instead.'])->withInput();
        }

        if ($error = $this->applyScopedLogo($request, $school, $data)) {
            return back()->withErrors(['logo' => $error])->withInput();
        }
        if ($request->hasFile('stamp')) {
            $school->stamp_path = $images->toWebp($request->file('stamp'), "schools/{$school->id}", maxDimension: 500);
        }
        if ($request->hasFile('signature')) {
            $school->signature_path = $images->toWebp($request->file('signature'), "schools/{$school->id}", maxDimension: 500);
        }

        $brandColor = $school->brand_color;
        if (! empty($data['brand_color'])) {
            if (($data['brand_color_scope'] ?? 'school') === 'sections') {
                foreach ($data['brand_color_sections'] as $sectionId) {
                    SchoolSectionBranding::updateOrCreate(['section_id' => $sectionId], ['brand_color' => $data['brand_color']]);
                }
            } else {
                $brandColor = $data['brand_color'];
            }
        }

        $school->fill([
            'brand_color' => $brandColor,
            'welcome_headline' => $data['welcome_headline'] ?? $school->welcome_headline,
            'welcome_message' => $data['welcome_message'] ?? $school->welcome_message,
            'login_layout' => $data['login_layout'] ?? $school->login_layout,
            'login_box_side' => $data['login_box_side'] ?? $school->login_box_side,
            'show_all_section_logos_on_login' => $request->boolean('show_all_section_logos_on_login'),
        ])->save();

        return back()->with('status', 'Your school branding has been updated.');
    }

    /**
     * Shared by updateBranding() and updateProfile() (user directive
     * 2026-09-22: "update both the school profile and branding page so
     * it does the same thing"). A freshly chosen file always wins; with
     * scope "sections" and no new file, reassigns the school's own
     * CURRENT logo to the ticked sections instead -- reassigning an
     * already-uploaded logo to one section shouldn't require
     * re-selecting the exact same file from disk. Returns an error
     * string on failure (nothing to assign), null on success/no-op.
     */
    protected function applyScopedLogo(Request $request, School $school, array $data): ?string
    {
        $freshPath = $request->hasFile('logo')
            ? $request->file('logo')->store("schools/{$school->id}", 'public')
            : null;

        if (($data['logo_scope'] ?? 'school') === 'sections') {
            $applyPath = $freshPath ?? $school->logo_path;
            if (! $applyPath) {
                return 'Choose a logo file, or set a whole-school logo first so there is something to assign to a section.';
            }
            foreach ($data['logo_sections'] ?? [] as $sectionId) {
                SchoolSectionBranding::updateOrCreate(['section_id' => $sectionId], ['logo_path' => $applyPath]);
            }
        } elseif ($freshPath) {
            $school->logo_path = $freshPath;
        }

        return $this->applyExtraLogoGroups($request, $school, $data);
    }

    /**
     * "Logo for the other sections" (user directive 2026-09-23: after saying
     * the first logo is only for some sections, let the admin upload
     * another logo for the rest, and another, then save it all once). Each
     * group is one uploaded file plus the sections it applies to. Only
     * honoured when the whole form is in "just some sections" mode.
     * Everything is checked before anything is stored, so a half filled
     * group never leaves the other groups half saved.
     */
    protected function applyExtraLogoGroups(Request $request, School $school, array $data): ?string
    {
        if (($data['logo_scope'] ?? 'school') !== 'sections') {
            return null;
        }

        $groups = [];
        foreach (array_keys($data['logo_groups'] ?? []) as $i) {
            $file = $request->file("logo_groups.{$i}.file");
            $ids = $data['logo_groups'][$i]['sections'] ?? [];
            if (! $file && empty($ids)) {
                continue;
            }
            if (! $file || empty($ids)) {
                return 'Each extra logo needs a file and at least one ticked section.';
            }
            $groups[] = [$file, $ids];
        }

        foreach ($groups as [$file, $ids]) {
            $path = $file->store("schools/{$school->id}", 'public');
            foreach ($ids as $sectionId) {
                SchoolSectionBranding::updateOrCreate(['section_id' => $sectionId], ['logo_path' => $path]);
            }
        }

        return null;
    }

    /**
     * A logo for ONE section, uploaded straight from that section's own row
     * (user directive 2026-09-23: "adding another logo to another section,
     * create the option under both school profile and branding"), rather
     * than only via the whole-school upload plus a tick-list.
     */
    public function updateSectionLogo(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.update');
        $school = $this->school($request);
        // route(), not a typed parameter: the domain wildcard binds positionally, see resetSectionBranding().
        $section = Section::findOrFail($request->route('sectionId'));
        $request->validate(['logo' => ['required', 'image', 'max:2048']]);

        $path = $request->file('logo')->store("schools/{$school->id}", 'public');
        SchoolSectionBranding::updateOrCreate(['section_id' => $section->id], ['logo_path' => $path]);

        return back()->with('status', "{$section->name} now has its own logo.");
    }

    /** Drop a section's own logo only, back to the school logo; its colour/report card overrides stay. */
    public function removeSectionLogo(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.update');
        $section = Section::findOrFail($request->route('sectionId'));
        SchoolSectionBranding::where('section_id', $section->id)->update(['logo_path' => null]);

        return back()->with('status', "{$section->name} now uses the school logo again.");
    }

    /**
     * Undo a section's own logo/colour override, back to inheriting the
     * school's default (user request 2026-09-09). Deletes the row
     * outright rather than nulling both columns, since a row with both
     * columns null is meaningless -- Section::branding()/resolvedLogoPath()
     * would treat it identically to no row at all anyway.
     */
    public function resetSectionBranding(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.update');
        // Read via route() rather than a typed method parameter: this
        // route sits under Route::domain('{school}.'.$domain), and a
        // typed scalar controller parameter binds POSITIONALLY against
        // the full route parameter list (domain param included), not by
        // name -- it would silently receive the school's slug instead
        // of the section id. See CLAUDE.md's "Domain routing + route
        // parameters gotcha", already hit more than once in this codebase.
        SchoolSectionBranding::where('section_id', $request->route('sectionId'))->delete();

        return back()->with('status', 'That section now uses the school default again.');
    }

    /**
     * School Profile (user directive 2026-07-17, Settings nav reorganization:
     * a "Profile" line distinct from Campuses/Branding/Houses). No screen
     * previously existed to edit a school's own institutional details
     * (name, motto, contact info); Branding only ever covered the visual
     * side (logo/colour/login page), Campuses only ever covered physical
     * locations. Name is intentionally excluded from the form: it drives
     * branded copy across the whole product (emails, login page, PDFs) in
     * ways silently changing it here could have far wider effects than a
     * quick edit screen should risk; a slug/subdomain change is likewise
     * a platform-admin-only action elsewhere.
     */
    public function editProfile(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.update');
        $school = $this->school($request);

        return view('school.settings-profile', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            // User request 2026-09-09: the per-section logo option
            // (built for Branding) should also be reachable from here,
            // since this screen already has its own logo upload.
            'sections' => Section::with('branding')->orderBy('sequence')->get(),
            // User directive 2026-09-21: a school's own word for "Term",
            // settable from here. See App\Support\TermLabel.
            'termLabel' => \App\Support\TermLabel::current(),
        ]);
    }

    /**
     * User request 2026-07-22: "one should also be able to upload the
     * school logo there. Also one should be able to add addition details
     * there if needed." Logo upload reuses updateBranding()'s own
     * existing storage logic (this screen is gated on the same
     * school_info.update permission that already gates it there), rather
     * than duplicating a second image-processing path; registration
     * number, established year, alternate phone, and website are
     * genuinely new optional fields.
     */
    public function updateProfile(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.update');
        $school = $this->school($request);
        $sectionIds = Section::pluck('id')->all();

        // A bare domain like "www.school.com" is what most admins
        // actually type, not a full "https://..." URI (user report
        // 2026-07-24: "its not accepting www.greenfieldacadmy.com as a
        // url, which is wrong"); add the scheme before validating/
        // storing rather than rejecting it.
        if ($request->filled('website') && ! preg_match('#^https?://#i', $request->input('website'))) {
            $request->merge(['website' => 'https://'.$request->input('website')]);
        }

        $data = $request->validate([
            'motto' => ['nullable', 'string', 'max:150'],
            'registration_number' => ['nullable', 'string', 'max:100'],
            'established_year' => ['nullable', 'integer', 'min:1800', 'max:'.now()->year],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'alternate_phone' => ['nullable', 'string', 'max:40'],
            'address' => ['nullable', 'string', 'max:500'],
            'website' => ['nullable', 'url', 'max:200'],
            'term_label' => ['nullable', 'string', 'max:40'],
            'logo' => ['nullable', 'image', 'max:2048'],
            // Same per-section scope Branding offers (user request
            // 2026-09-09: "the logo change should be at the school
            // profile page too"), so a school can set a section's own
            // logo from either screen. Rule::in(), not exists:, for the
            // same reason updateBranding() avoids it -- exists: defaults
            // to the platform connection, not the tenant one.
            'logo_scope' => ['nullable', Rule::in(['school', 'sections'])],
            'logo_sections' => ['nullable', 'array'],
            'logo_sections.*' => [Rule::in($sectionIds)],
            'logo_groups' => ['nullable', 'array'],
            'logo_groups.*.file' => ['nullable', 'image', 'max:2048'],
            'logo_groups.*.sections' => ['nullable', 'array'],
            'logo_groups.*.sections.*' => [Rule::in($sectionIds)],
        ]);

        if (($data['logo_scope'] ?? 'school') === 'sections' && empty($data['logo_sections'])) {
            return back()->withErrors(['logo_sections' => 'Tick at least one section, or choose "Whole school" instead.'])->withInput();
        }

        if ($error = $this->applyScopedLogo($request, $school, $data)) {
            return back()->withErrors(['logo' => $error])->withInput();
        }

        // term_label lives in tenant Settings, not on the platform School
        // model (App\Support\TermLabel), so it is excluded from the
        // school fill() below like the other non-column fields already are.
        \App\Models\Tenant\Setting::put('term_label', $data['term_label'] ?? null);

        $school->fill(collect($data)->except(['logo', 'logo_scope', 'logo_sections', 'logo_groups', 'term_label'])->all())->save();

        return back()->with('status', 'Your school profile has been updated.');
    }
}
