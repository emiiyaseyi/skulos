<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\Role;
use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Services\Communication\NotificationService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Send Announcement (Dashboard Design Cross-Check, 2026-07-13: "Blocked,
 * announcements can be viewed now, not composed, no compose screen yet").
 * `Announcement`, `NotificationService::dispatchAnnouncement()`, and
 * `CommunicationService::emailAnnouncement()` already existed, fully
 * built and working, this is the missing compose screen in front of them,
 * not new delivery logic.
 *
 * The {school} route sits under a domain wildcard, so nested route params
 * are read via $request->route() rather than typed method parameters
 * (the recurring gotcha documented in CLAUDE.md).
 */
class AnnouncementController extends Controller
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
        $this->authorizeAction($request, 'communication.view');
        $school = $this->school($request);

        return view('school.announcements.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            // Memos (type=memo) have their own screen with read receipts;
            // this list is announcements only, so the two do not bleed into
            // each other.
            'announcements' => Announcement::announcements()->orderByDesc('created_at')->paginate(20),
            'canCompose' => app(Rbac::class)->check(Auth::user(), $school, 'communication.broadcast'),
        ]);
    }

    /**
     * Communication History (user directive 2026-07-16, "ALL THERE": build
     * out the remaining Reports & Analytics/Communication "Soon" items,
     * covers the communication_history catalog feature). Announcements and
     * Messages/Broadcasts already share one underlying `Announcement`
     * table (`type` = announcement or broadcast, see MessageController's
     * own docblock), each with its own single-type browse screen; this is
     * the missing unified view across both, one chronological, filterable
     * ledger of everything this school has sent, each row's real
     * delivered/read counts via the same `notifications` withCount()
     * pattern MessageController's own screen already uses, not a second
     * computation.
     */
    public function history(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'communication.view');
        $school = $this->school($request);

        $type = $request->query('type');

        $items = Announcement::query()
            // A platform-owner broadcast delivered into this school's
            // tenant DB (messaging increment 3) is something the school
            // received, not something it sent, so it stays off this
            // school's own "what have we sent" ledger, same exclusion
            // MessageController's screen already applies.
            ->whereNull('platform_broadcast_id')
            ->when(in_array($type, [Announcement::TYPE_ANNOUNCEMENT, Announcement::TYPE_BROADCAST], true), fn ($q) => $q->where('type', $type))
            ->with('section', 'classArm.schoolClass')
            ->withCount([
                'notifications as delivered_count',
                'notifications as read_count' => fn ($q) => $q->whereNotNull('read_at'),
            ])
            ->orderByDesc('created_at')
            ->paginate(20)
            ->withQueryString();

        return view('school.announcements.history', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'items' => $items,
            'type' => $type,
        ]);
    }

    /**
     * Who this one announcement/message actually reached, and who has and
     * hasn't acknowledged it yet (user request 2026-07-21: "ONE SHOULD BE
     * ABLE TO SEE LIST OF THOSE WHO ACKNOWLEDGE EACH COMMUNICATION SO AS
     * TO ALSO NUDGE THOSE WHO ARE YET TO DO SO"). The "X of Y" figure on
     * the history ledger already existed; this is the drill-down behind it.
     */
    public function showRecipients(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'communication.view');
        $school = $this->school($request);
        $announcement = Announcement::findOrFail($request->route('announcementId'));

        $notifications = \App\Models\Tenant\Notification::where('announcement_id', $announcement->id)
            ->with('user')
            ->orderByRaw('read_at is not null')
            ->orderBy('created_at')
            ->get();

        return view('school.announcements.recipients', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'announcement' => $announcement,
            'notifications' => $notifications,
        ]);
    }

    /** Nudge every recipient who hasn't acknowledged this announcement yet, one in-app reminder each. */
    public function nudgeUnacknowledged(Request $request): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'communication.broadcast');
        $announcement = Announcement::findOrFail($request->route('announcementId'));

        $pending = \App\Models\Tenant\Notification::where('announcement_id', $announcement->id)
            ->whereNull('read_at')
            ->get();

        foreach ($pending as $notification) {
            // Deliberately no announcement_id here: the history ledger's
            // "X of Y acknowledged" figure is a withCount() over every
            // notification carrying this announcement's id, a reminder
            // must not inflate that as if it were a second delivery.
            \App\Models\Tenant\Notification::create([
                'user_id' => $notification->user_id,
                'title' => 'Reminder: '.$announcement->title,
                'body' => 'You have not yet acknowledged this. Please review it when you can.',
                'category' => 'announcement',
                'type' => \App\Models\Tenant\Notification::typeFor('announcement'),
            ]);
        }

        return back()->with('status', $pending->isEmpty()
            ? 'Everyone has already acknowledged this.'
            : "{$pending->count()} recipient(s) nudged.");
    }

    public function create(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'communication.broadcast');
        $school = $this->school($request);

        $classArms = ClassArm::with('schoolClass.section', 'arm')->get()
            ->sortBy(fn (ClassArm $arm) => $arm->orderKey())->values();

        return view('school.announcements.create', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'classArms' => $classArms,
        ]);
    }

    /**
     * Unlike MessageController::store() (a rich-text composer producing
     * real HTML, cleaned via HtmlSanitizer before storage), this body
     * comes from a plain <textarea> and is stored as plain text on
     * purpose (audit follow-up, 2026-07-17: confirmed via
     * HtmlSanitizer::clean() that running arbitrary plain text through it
     * corrupts legitimate content containing angle brackets, e.g. "Use
     * <JSS2> as the code" silently loses "JSS2" since it parses as an
     * unrecognized tag). Every current render path already escapes this
     * field ({{ }} or strip_tags()), which is the correct and sufficient
     * protection for plain text: any FUTURE raw {!! !!} render of this
     * field would need its own escaping at that point, not a
     * sanitizer this field doesn't actually need today.
     */
    public function store(Request $request, NotificationService $notifications): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'communication.broadcast');
        $school = $this->school($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
            'audience' => ['required', Rule::in(['all', 'parents', 'teachers', 'staff', 'students', 'class'])],
            'class_arm_id' => ['nullable', 'required_if:audience,class', 'exists:tenant.class_arms,id'],
        ]);

        $announcement = Announcement::create([
            'title' => $data['title'],
            'body' => $data['body'],
            'type' => Announcement::TYPE_ANNOUNCEMENT,
            'audience' => $data['audience'],
            'class_arm_id' => $data['audience'] === 'class' ? $data['class_arm_id'] : null,
            'status' => Announcement::STATUS_DRAFT,
            'created_by_user_id' => Auth::id(),
        ]);

        $recipients = $this->resolveRecipients($school, $data['audience'], $data['class_arm_id'] ?? null);
        $result = $notifications->dispatchAnnouncement($announcement, $recipients);

        return redirect()->route('school.announcements.index', ['school' => $school->slug])->with('status',
            "Announcement published to {$result['in_app']} recipient(s), {$result['email_sent']} emailed.");
    }

    /**
     * Announcements keep their own broadcast audience model (which includes
     * a non-teaching "staff" audience the newer message composer does not),
     * so they resolve recipients here rather than through the messaging
     * AudienceResolver, whose audiences are role-group x scope.
     *
     * @return list<array{user_id:int, email:?string, audience:string}>
     */
    protected function resolveRecipients(School $school, string $audience, ?int $classArmId): array
    {
        if ($audience === 'class' && $classArmId) {
            return $this->classRecipients($classArmId);
        }

        $familyRoleIds = Role::whereIn('slug', config('skulos.family_scoped_roles', ['parent', 'student']))->pluck('id');
        $teacherRoleIds = Role::whereIn('slug', ['teacher', 'class-teacher', 'subject-teacher', 'class-assistant'])->pluck('id');
        $parentRoleIds = Role::where('slug', 'parent')->pluck('id');
        $studentRoleIds = Role::where('slug', 'student')->pluck('id');

        $query = $school->users()->wherePivot('status', 'active');

        match ($audience) {
            'parents' => $query->whereIn('role_id', $parentRoleIds),
            'students' => $query->whereIn('role_id', $studentRoleIds),
            'teachers' => $query->whereIn('role_id', $teacherRoleIds),
            'staff' => $query->whereNotIn('role_id', $familyRoleIds),
            default => null, // 'all'
        };

        $users = $query->get();
        $teacherIds = $teacherRoleIds->all();
        $parentIds = $parentRoleIds->all();
        $studentIds = $studentRoleIds->all();

        return $users->map(function ($u) use ($teacherIds, $parentIds, $studentIds) {
            $roleId = $u->pivot->role_id;
            $label = in_array($roleId, $parentIds, true) ? 'parents'
                : (in_array($roleId, $studentIds, true) ? 'students'
                : (in_array($roleId, $teacherIds, true) ? 'teachers' : 'staff'));

            return ['user_id' => $u->id, 'email' => $u->email, 'audience' => $label];
        })->all();
    }

    /**
     * @return list<array{user_id:int, email:?string, audience:string}>
     */
    protected function classRecipients(int $classArmId): array
    {
        $students = Student::where('current_class_arm_id', $classArmId)->active()->whereNotNull('user_id')->with('user')->get();
        $studentIds = Student::where('current_class_arm_id', $classArmId)->active()->pluck('id');
        $guardians = StudentGuardian::whereIn('student_id', $studentIds)->whereNotNull('guardian_user_id')->with('user')->get()
            ->unique('guardian_user_id');

        $recipients = [];
        foreach ($students as $s) {
            if ($s->user) {
                $recipients[] = ['user_id' => $s->user->id, 'email' => $s->user->email, 'audience' => 'students'];
            }
        }
        foreach ($guardians as $g) {
            if ($g->user) {
                $recipients[] = ['user_id' => $g->user->id, 'email' => $g->user->email, 'audience' => 'parents'];
            }
        }

        return $recipients;
    }
}
