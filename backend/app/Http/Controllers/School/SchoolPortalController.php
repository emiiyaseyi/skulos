<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Notification;
use App\Models\Tenant\Student;
use App\Models\Tenant\Staff;
use App\Models\Tenant\CalendarEvent;
use App\Services\Dashboard\DashboardPreferences;
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

    /** Branded welcome page (public). */
    public function welcome(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);

        return view('school.welcome', [
            'school' => $school,
            'accent' => $school->branding()['color'],
        ]);
    }

    /** Dashboard with the signed in user's notification feed. */
    public function dashboard(Request $request, DashboardPreferences $prefs): \Illuminate\View\View
    {
        $school = $this->school($request);
        $feed = Notification::feedFor(Auth::id());

        return view('school.dashboard', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'feed' => $feed,
            'stats' => [
                'students' => Student::count(),
                'staff' => Staff::count(),
                'events' => CalendarEvent::count(),
            ],
            'upcoming' => CalendarEvent::upcoming()->limit(5)->get(),
            'layout' => $prefs->layout(),
            'widgets' => $prefs->widgets(),
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

    /** School branding settings form. */
    public function editBranding(Request $request): \Illuminate\View\View
    {
        $school = $this->school($request);

        return view('school.settings-branding', [
            'school' => $school,
            'accent' => $school->branding()['color'],
        ]);
    }

    public function updateBranding(Request $request): \Illuminate\Http\RedirectResponse
    {
        $school = $this->school($request);

        $data = $request->validate([
            'brand_color' => ['nullable', 'regex:/^#([0-9a-fA-F]{6})$/'],
            'welcome_headline' => ['nullable', 'string', 'max:120'],
            'welcome_message' => ['nullable', 'string', 'max:400'],
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
        ])->save();

        return back()->with('status', 'Your school branding has been updated.');
    }
}
