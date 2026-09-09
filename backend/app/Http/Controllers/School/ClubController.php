<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Club;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Services\Bulk\CsvReader;
use App\Services\Rbac\Rbac;
use App\Services\Rbac\TeacherScope;
use Illuminate\Http\Request;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * School clubs (2026-08-16). "Club" already printed on the report card
 * but was only ever free text typed per student per term, so nothing
 * linked a student to a club and no one could see who was in one.
 *
 * Deliberately mirrors HouseController rather than inventing a second
 * way of doing the same job: a default catalog to load in one click,
 * even auto-distribution, a manual picker per member, bulk actions, and
 * a CSV round trip. An admin who already knows Houses knows this.
 *
 * The {school} route sits under a domain wildcard, so nested route
 * params are read via $request->route() rather than typed method
 * parameters (the recurring gotcha documented in CLAUDE.md).
 */
class ClubController extends Controller
{
    protected function school(Request $request): School
    {
        return $request->attributes->get('school');
    }

    /**
     * Same explicit parent/student denial Houses carries. Both roles
     * hold 'students.view' for their own narrow reason (seeing their
     * own child), which would otherwise let them open a school-wide
     * administration screen complete with bulk assignment templates.
     * Found live on Houses 2026-07-26; the identical exposure exists
     * here for the identical reason, so it is closed the same way
     * rather than waiting to be reported again.
     *
     * subject-teacher added 2026-09-09 (direct instruction: "subject
     * teachers can[not] see the club menu at all") -- their
     * 'students.view' grant exists for genuinely unrelated screens, and
     * incidentally opened this one too, same shape of over-exposure as
     * parent/student above.
     */
    protected function authorizeAction(Request $request, string $permission): void
    {
        $school = $this->school($request);
        $user = Auth::user();
        $role = $user->roleIn($school);
        abort_if(in_array($role?->slug, ['parent', 'student', 'subject-teacher'], true), 403, 'You do not have permission for this.');

        abort_unless(
            app(Rbac::class)->check($user, $school, $permission),
            403,
            'You do not have permission for this.'
        );
    }

    /**
     * Clubs are optional (2026-08-16, direct instruction): a school
     * decides whether they appear on a student profile and on the
     * report card at all. Off by default, so a school that does not run
     * clubs never sees an empty "Club" line on either -- which is what
     * the report card did before this, since it always printed the row.
     */
    public static function enabled(): bool
    {
        return (bool) Setting::grab('clubs_enabled', false);
    }

    public function index(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.view');
        $school = $this->school($request);

        // A scoped teacher (class teacher, class assistant, or a plain
        // "teacher" role) never gets the school-wide admin screen --
        // even though the earlier admin-only checks on every mutating
        // action below already blocked them from actually changing
        // anything, they were still shown a full "add a club" / "bulk
        // upload" / "retire" management screen that only LOOKED like it
        // worked (2026-09-09 direct report: "class teachers... have
        // access to clubs and can make changes to it"). They get their
        // own read-only view instead: only clubs with a student in
        // their own class, showing that class's own distribution --
        // never the whole school's, and never a club with nobody in it.
        $armIds = app(TeacherScope::class)->classArmIds(Auth::user(), $school);
        if ($armIds !== null) {
            return $this->scopedIndex($school, $armIds);
        }

        return view('school.clubs.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'clubs' => Club::withCount(['students', 'staff', 'guardianMembers'])->orderBy('name')->get(),
            'defaults' => config('skulos.default_clubs', []),
            'unassignedStudents' => Student::active()->whereNull('club_id')->count(),
            'clubsEnabled' => self::enabled(),
        ]);
    }

    /** Read-only, scoped to a teacher's own class -- see index() above for why. */
    protected function scopedIndex(School $school, Collection $armIds): \Illuminate\View\View
    {
        $studentIds = Student::active()->whereIn('current_class_arm_id', $armIds)->pluck('id');

        $clubs = Club::whereHas('students', fn ($q) => $q->whereIn('id', $studentIds))
            ->with(['students' => fn ($q) => $q->whereIn('id', $studentIds)->orderBy('first_name')])
            ->orderBy('name')
            ->get();

        return view('school.clubs.my-class', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'clubs' => $clubs,
            'clubsEnabled' => self::enabled(),
        ]);
    }

    /** Turn the club field on or off across student profiles and report cards. */
    public function toggleEnabled(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $enabled = $request->boolean('enabled');
        Setting::put('clubs_enabled', $enabled);

        return back()->with('status', $enabled
            ? 'Clubs now appear on student profiles and report cards.'
            : 'Clubs are hidden from student profiles and report cards. Existing assignments are kept.');
    }

    public function store(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('clubs', 'name')],
            'description' => ['nullable', 'string', 'max:255'],
        ]);

        Club::create($data);

        return back()->with('status', "\"{$data['name']}\" added.");
    }

    /** Load the default club catalog in one click, skipping any that already exist. */
    public function applyDefaults(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $added = 0;
        foreach (config('skulos.default_clubs', []) as $name) {
            if (! Club::where('name', $name)->exists()) {
                Club::create(['name' => $name]);
                $added++;
            }
        }

        return back()->with('status', $added
            ? "{$added} club(s) added."
            : 'Every default club already exists.');
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $club = Club::findOrFail($request->route('clubId'));

        $data = $request->validate([
            'name' => ['required', 'string', 'max:100', Rule::unique('clubs', 'name')->ignore($club->id)],
            'description' => ['nullable', 'string', 'max:255'],
            'patron_staff_id' => ['nullable', 'integer'],
            'assistant_patron_staff_id' => ['nullable', 'integer'],
        ]);

        $club->update($data);

        return back()->with('status', "\"{$club->name}\" updated.");
    }

    public function toggleActive(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $club = Club::findOrFail($request->route('clubId'));
        $club->update(['is_active' => ! $club->is_active]);

        return back()->with('status', $club->is_active
            ? "\"{$club->name}\" is active again and can be assigned."
            : "\"{$club->name}\" is retired. Its members keep their assignment, but it can no longer be assigned to anyone new.");
    }

    public function destroy(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $club = Club::findOrFail($request->route('clubId'));

        // Members are released rather than deleted: removing a club must
        // never take a student record with it.
        Student::where('club_id', $club->id)->update(['club_id' => null]);
        Staff::where('club_id', $club->id)->update(['club_id' => null]);
        StudentGuardian::where('club_id', $club->id)->update(['club_id' => null]);
        $name = $club->name;
        $club->delete();

        return back()->with('status', "\"{$name}\" removed. Anyone in it is now unassigned.");
    }

    /** Bulk retire/reactivate/delete, the same shape the Houses list uses. */
    public function bulkAction(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $data = $request->validate([
            'club_ids' => ['required', 'array', 'min:1'],
            'club_ids.*' => ['integer'],
            'action' => ['required', 'in:retire,reactivate,delete'],
        ]);

        $clubs = Club::whereIn('id', $data['club_ids'])->get();

        foreach ($clubs as $club) {
            if ($data['action'] === 'delete') {
                Student::where('club_id', $club->id)->update(['club_id' => null]);
                Staff::where('club_id', $club->id)->update(['club_id' => null]);
                StudentGuardian::where('club_id', $club->id)->update(['club_id' => null]);
                $club->delete();
            } else {
                $club->update(['is_active' => $data['action'] === 'reactivate']);
            }
        }

        return back()->with('status', count($clubs).' club(s) updated.');
    }

    /**
     * Even auto-distribution across active clubs, for a school that
     * simply wants everyone in something. Only ever fills members who
     * have NO club yet, so running it again never reshuffles anyone an
     * admin placed deliberately.
     */
    public function distributeMembers(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $clubIds = Club::active()->orderBy('name')->pluck('id');
        if ($clubIds->isEmpty()) {
            return back()->with('error', 'Add at least one active club before distributing members into it.');
        }

        $counts = ['student' => 0, 'staff' => 0, 'parent' => 0];
        $i = 0;
        foreach (Student::active()->whereNull('club_id')->orderBy('id')->cursor() as $student) {
            $student->update(['club_id' => $clubIds[$i++ % $clubIds->count()]]);
            $counts['student']++;
        }
        $i = 0;
        foreach (Staff::whereNull('club_id')->orderBy('id')->cursor() as $staffMember) {
            $staffMember->update(['club_id' => $clubIds[$i++ % $clubIds->count()]]);
            $counts['staff']++;
        }
        $i = 0;
        foreach (StudentGuardian::whereNull('club_id')->orderBy('id')->cursor() as $guardian) {
            $guardian->update(['club_id' => $clubIds[$i++ % $clubIds->count()]]);
            $counts['parent']++;
        }

        return back()->with('status', "{$counts['student']} student(s), {$counts['staff']} staff member(s), and {$counts['parent']} parent(s) distributed across your clubs.");
    }

    /** Per-student picker with a "still unassigned" filter. */
    public function assignForm(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.manage_settings');
        $school = $this->school($request);

        $search = trim((string) $request->query('q'));
        $unassignedOnly = $request->boolean('unassigned');

        $students = Student::active()
            ->with('club', 'currentClassArm.schoolClass', 'currentClassArm.arm')
            ->when($search, fn ($q) => $q->where(function ($w) use ($search) {
                $w->where('admission_number', 'like', "%{$search}%")
                    ->orWhere('first_name', 'like', "%{$search}%")
                    ->orWhere('last_name', 'like', "%{$search}%");
            }))
            ->when($unassignedOnly, fn ($q) => $q->whereNull('club_id'))
            ->orderBy('last_name')
            ->paginate(30)
            ->withQueryString();

        return view('school.clubs.assign', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'students' => $students,
            'clubs' => Club::active()->orderBy('name')->get(),
            'search' => $search,
            'unassignedOnly' => $unassignedOnly,
        ]);
    }

    /** One save for every student on the page, writing only real changes. */
    public function assign(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $data = $request->validate([
            'clubs' => ['required', 'array'],
            'clubs.*' => ['nullable', 'integer'],
        ]);

        $changed = 0;
        foreach ($data['clubs'] as $studentId => $clubId) {
            $student = Student::find($studentId);
            if (! $student) {
                continue;
            }
            $new = $clubId ?: null;
            if ($student->club_id !== $new) {
                $student->update(['club_id' => $new]);
                $changed++;
            }
        }

        return back()->with('status', "{$changed} student(s) updated.");
    }

    /** Every current student, pre-filled, for editing offline. */
    public function bulkTemplate(Request $request): \Symfony\Component\HttpFoundation\Response
    {
        $this->authorizeAction($request, 'students.view');

        $out = fopen('php://temp', 'r+');
        fputcsv($out, ['admission_number', 'class', 'club']);

        Student::active()->with('club', 'currentClassArm.schoolClass', 'currentClassArm.arm')
            ->orderBy('last_name')->each(function (Student $student) use ($out) {
                fputcsv($out, [
                    $student->admission_number,
                    $student->currentClassArm?->label() ?? '',
                    $student->club?->name ?? '',
                ]);
            });

        rewind($out);

        return response((string) stream_get_contents($out), 200, [
            'Content-Type' => 'text/csv',
            'Content-Disposition' => 'attachment; filename="club-assignment.csv"',
        ]);
    }

    /**
     * Re-upload of the template above. Only the club column is acted on;
     * "class" is a read-only reference. A blank club cell leaves that
     * student alone -- this is a "fill in what changed" round trip, not
     * a full re-import, so a partly completed sheet can never wipe
     * assignments nobody meant to touch. Same rule Houses follows.
     */
    public function bulkUpload(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'students.manage_settings');

        $request->validate(['file' => ['required', 'file', 'mimes:csv,txt', 'max:2048']]);

        // rows() takes the file's CONTENTS, not its path (the same call
        // Houses makes). Passing the path parses the filename as a
        // one-line CSV, which silently matches nothing at all.
        $csv = file_get_contents($request->file('file')->getRealPath());

        try {
            $rows = app(CsvReader::class)->rows($csv);
        } catch (\InvalidArgumentException $e) {
            return back()->with('error', $e->getMessage());
        }
        $clubsByName = Club::active()->get()->keyBy(fn ($c) => mb_strtolower($c->name));

        $updated = 0;
        $problems = [];
        foreach ($rows as $index => $row) {
            $line = $index + 2;
            $admissionNumber = trim((string) ($row['admission_number'] ?? ''));
            $clubName = trim((string) ($row['club'] ?? ''));

            if ($admissionNumber === '' || $clubName === '') {
                continue;
            }

            $student = Student::where('admission_number', $admissionNumber)->first();
            if (! $student) {
                $problems[] = "Row {$line}: no student with admission number {$admissionNumber}.";

                continue;
            }

            $club = $clubsByName->get(mb_strtolower($clubName));
            if (! $club) {
                $problems[] = "Row {$line}: no active club named \"{$clubName}\".";

                continue;
            }

            if ($student->club_id !== $club->id) {
                $student->update(['club_id' => $club->id]);
                $updated++;
            }
        }

        $message = "{$updated} student(s) updated from the uploaded file.";
        if ($problems) {
            // Same partial-save convention as every other import here:
            // good rows are kept, bad ones reported by line number.
            $message .= ' '.count($problems).' row(s) skipped: '.implode(' ', array_slice($problems, 0, 5));
        }

        return back()->with('status', $message);
    }

    /** A club's own page: who is in it, grouped the way a house's page is. */
    public function show(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'students.view');
        $school = $this->school($request);
        $club = Club::findOrFail($request->route('clubId'));

        return view('school.clubs.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'club' => $club,
            'students' => $club->students()->with('currentClassArm.schoolClass', 'currentClassArm.arm')->orderBy('last_name')->get(),
            'staffMembers' => $club->staff()->orderBy('last_name')->get(),
            'guardians' => $club->guardianMembers()->orderBy('name')->get(),
            'allStaff' => Staff::orderBy('last_name')->get(),
        ]);
    }
}
