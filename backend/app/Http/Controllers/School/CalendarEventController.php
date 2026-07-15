<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\CalendarEvent;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Subject;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Create Event (Dashboard Design Cross-Check, 2026-07-13: "Blocked, no
 * calendar event creation screen exists yet, only the auto-seeded default
 * notable days"). `CalendarEvent` and `CalendarService::seedDefaults()`
 * already existed, this is the first real browse/create screen for a
 * school to add its own events on top of the defaults.
 *
 * The {school} route sits under a domain wildcard, so nested route params
 * are read via $request->route() rather than typed method parameters
 * (the recurring gotcha documented in CLAUDE.md).
 */
class CalendarEventController extends Controller
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
        $this->authorizeAction($request, 'calendar.view');
        $school = $this->school($request);

        return view('school.calendar.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'events' => CalendarEvent::orderBy('starts_on')->paginate(30),
            'canCreate' => app(Rbac::class)->check(Auth::user(), $school, 'calendar.create'),
        ]);
    }

    public function create(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'calendar.create');
        $school = $this->school($request);

        return view('school.calendar.create', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'categories' => config('skulos.calendar_event_categories'),
            'subjects' => Subject::orderBy('name')->get(),
            'classArms' => ClassArm::with('schoolClass.section', 'arm')->get()
                ->sortBy(fn (ClassArm $arm) => $arm->orderKey())
                ->values(),
        ]);
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'calendar.create');
        $school = $this->school($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(config('skulos.calendar_event_categories'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'all_day' => ['sometimes', 'boolean'],
            // Only meaningful for category=exam (the "simple exam calendar"
            // scope decision 2026-07-15: date + subject + class arm per
            // exam sitting, no rooms/invigilators), ignored for every other
            // category regardless of what is posted.
            'subject_id' => ['nullable', 'integer', 'exists:tenant.subjects,id'],
            'class_arm_id' => ['nullable', 'integer', 'exists:tenant.class_arms,id'],
        ]);

        $isExam = $data['category'] === 'exam';

        CalendarEvent::create([
            'title' => $data['title'],
            'category' => $data['category'],
            'description' => $data['description'] ?? null,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'all_day' => $request->boolean('all_day'),
            'is_default' => false,
            'subject_id' => $isExam ? ($data['subject_id'] ?? null) : null,
            'class_arm_id' => $isExam ? ($data['class_arm_id'] ?? null) : null,
            'created_by_user_id' => Auth::id(),
        ]);

        return redirect()->route('school.calendar.index', ['school' => $school->slug])
            ->with('status', 'Event added to the calendar.');
    }

    /**
     * Edit an event, including a seeded default one, whose starts_on is
     * often null until a school fills it in (user directive 2026-07-15:
     * "EACH CALENDAR DATE CAN BE CLICKED AND EDITED"), matching how the
     * index view already lets a default event's Remove button stay
     * hidden while everything else (including editing) stays open to it.
     */
    public function edit(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'calendar.create');
        $school = $this->school($request);
        $event = CalendarEvent::findOrFail($request->route('eventId'));

        return view('school.calendar.edit', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'event' => $event,
            'categories' => config('skulos.calendar_event_categories'),
            'subjects' => Subject::orderBy('name')->get(),
            'classArms' => ClassArm::with('schoolClass.section', 'arm')->get()
                ->sortBy(fn (ClassArm $arm) => $arm->orderKey())
                ->values(),
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'calendar.create');
        $school = $this->school($request);
        $event = CalendarEvent::findOrFail($request->route('eventId'));

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'category' => ['required', Rule::in(config('skulos.calendar_event_categories'))],
            'description' => ['nullable', 'string', 'max:2000'],
            'starts_on' => ['required', 'date'],
            'ends_on' => ['nullable', 'date', 'after_or_equal:starts_on'],
            'all_day' => ['sometimes', 'boolean'],
            'subject_id' => ['nullable', 'integer', 'exists:tenant.subjects,id'],
            'class_arm_id' => ['nullable', 'integer', 'exists:tenant.class_arms,id'],
        ]);

        $isExam = $data['category'] === 'exam';

        $event->update([
            'title' => $data['title'],
            'category' => $data['category'],
            'description' => $data['description'] ?? null,
            'starts_on' => $data['starts_on'],
            'ends_on' => $data['ends_on'] ?? null,
            'all_day' => $request->boolean('all_day'),
            'subject_id' => $isExam ? ($data['subject_id'] ?? null) : null,
            'class_arm_id' => $isExam ? ($data['class_arm_id'] ?? null) : null,
        ]);

        return redirect()->route('school.calendar.index', ['school' => $school->slug])
            ->with('status', 'Event updated.');
    }

    public function destroy(Request $request, string $eventId): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'calendar.delete');
        $school = $this->school($request);

        CalendarEvent::findOrFail($eventId)->delete();

        return redirect()->route('school.calendar.index', ['school' => $school->slug])
            ->with('status', 'Event removed.');
    }
}
