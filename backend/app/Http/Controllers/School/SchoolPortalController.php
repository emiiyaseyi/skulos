<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Student;
use App\Models\Tenant\Staff;
use App\Models\Tenant\CalendarEvent;
use App\Services\Dashboard\DashboardPreferences;
use App\Services\Dashboard\RoleDashboard;
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
            'upcomingHolidays' => CalendarEvent::where('category', 'holiday')->upcoming()->limit(5)->get(),
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

    public function markNotificationRead(Request $request, string $school, int $id): \Illuminate\Http\RedirectResponse
    {
        $n = Notification::forUser(Auth::id())->findOrFail($id);
        $n->markRead();

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

        return view('school.settings-branding', [
            'school' => $school,
            'accent' => $school->branding()['color'],
        ]);
    }

    public function updateBranding(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.update');
        $school = $this->school($request);

        $data = $request->validate([
            'brand_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'welcome_headline' => ['nullable', 'string', 'max:120'],
            'welcome_message' => ['nullable', 'string', 'max:400'],
            'login_layout' => ['nullable', Rule::in(array_keys(config('skulos.login_layouts')))],
            'login_box_side' => ['nullable', Rule::in(['left', 'right'])],
            'logo' => ['nullable', 'image', 'max:2048'],
        ]);

        if ($request->hasFile('logo')) {
            $path = $request->file('logo')->store("schools/{$school->id}", 'public');
            $school->logo_path = $path;
        }

        $school->fill([
            'brand_color' => $data['brand_color'] ?? $school->brand_color,
            'welcome_headline' => $data['welcome_headline'] ?? $school->welcome_headline,
            'welcome_message' => $data['welcome_message'] ?? $school->welcome_message,
            'login_layout' => $data['login_layout'] ?? $school->login_layout,
            'login_box_side' => $data['login_box_side'] ?? $school->login_box_side,
        ])->save();

        return back()->with('status', 'Your school branding has been updated.');
    }
}
