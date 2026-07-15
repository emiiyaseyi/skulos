<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\ChatThread;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Communication\ChatService;
use App\Services\Media\ImageProcessingService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Private 1-to-1 chat between a teacher and a student's guardian, about
 * that student (messaging increment 4, 2026-07-15, user directive: "NOTE
 * THAT MESSAGES WILL HAVE A CHAT WIDGET", reply model confirmed as
 * "private 1-to-1 threads"). Every action re-derives the teacher<->
 * guardian pairing from TeacherScope/FamilyScope rather than trusting a
 * stored thread's participants alone, so access is revoked automatically
 * the moment a teaching assignment or guardianship changes, the same "no
 * stale grant" posture RBAC uses elsewhere.
 *
 * Text messages are stored and shown as escaped plain text, not the rich
 * text formatting the broadcast composer has, a quick chat reply does not
 * need a formatting toolbar.
 */
class ChatController extends Controller
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
        $user = Auth::user();

        $threads = ChatThread::where('user_one_id', $user->id)->orWhere('user_two_id', $user->id)
            ->with('student')
            ->orderByDesc('last_message_at')
            ->get()
            ->map(function (ChatThread $thread) use ($user) {
                return [
                    'thread' => $thread,
                    'other' => $thread->otherParticipant($user->id),
                    'unread' => $thread->unreadCountFor($user->id),
                ];
            });

        return view('school.chat.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'threads' => $threads,
        ]);
    }

    /**
     * Resolves (or creates) the thread between the signed-in user and
     * ?user_id= about ?student_id=, then redirects to it. The link that
     * points here (a "Message" button on a student's guardian row, or a
     * "Message teacher" button on a parent's child page) already knows
     * exactly who the other participant is, so no picker screen is needed.
     */
    public function start(Request $request, ChatService $chat): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'communication.view');
        $school = $this->school($request);
        $user = Auth::user();

        $data = $request->validate([
            'student_id' => ['required', 'integer', 'exists:tenant.students,id'],
            'user_id' => ['required', 'integer'],
        ]);
        $student = Student::findOrFail($data['student_id']);
        $target = User::findOrFail($data['user_id']);

        $this->authorizePair($chat, $user, $target, $student, $school);

        if ($chat->isTeacherOf($user, $school, $student) && ! $chat->teacherParentMessagingEnabled()) {
            return redirect()->route('school.dashboard', ['school' => $school->slug])
                ->with('error', 'Messaging teachers to parents has been turned off by your school admin.');
        }

        $thread = $chat->threadFor($user, $target, $student);

        return redirect()->route('school.chat.show', ['school' => $school->slug, 'threadId' => $thread->id]);
    }

    public function show(Request $request, ChatService $chat): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'communication.view');
        $school = $this->school($request);
        $user = Auth::user();
        $thread = ChatThread::with('student', 'messages')->findOrFail($request->route('threadId'));

        abort_unless(in_array($user->id, $thread->participantIds(), true), 404);

        // Mark the other participant's messages read on open, the same
        // acknowledge-on-view pattern the broadcast pop-up uses.
        $thread->messages()->where('sender_user_id', '!=', $user->id)->whereNull('read_at')->update(['read_at' => now()]);

        return view('school.chat.show', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'thread' => $thread,
            'other' => $thread->otherParticipant($user->id),
            'messages' => $thread->messages,
            'canSend' => ! $chat->isTeacherOf($user, $school, $thread->student) || $chat->teacherParentMessagingEnabled(),
        ]);
    }

    public function store(Request $request, ChatService $chat, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'communication.view');
        $school = $this->school($request);
        $user = Auth::user();
        $thread = ChatThread::with('student')->findOrFail($request->route('threadId'));

        abort_unless(in_array($user->id, $thread->participantIds(), true), 404);

        if ($chat->isTeacherOf($user, $school, $thread->student) && ! $chat->teacherParentMessagingEnabled()) {
            return back()->withErrors(['body' => 'Messaging teachers to parents has been turned off by your school admin.']);
        }

        $data = $request->validate([
            'body' => ['nullable', 'string', 'max:4000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);
        $body = trim((string) ($data['body'] ?? ''));
        $imagePath = $request->hasFile('image')
            ? $images->toWebp($request->file('image'), "chat/{$school->slug}")
            : null;

        if ($body === '' && ! $imagePath) {
            return back()->withErrors(['body' => 'Write a message or attach an image.']);
        }

        $thread->messages()->create([
            'sender_user_id' => $user->id,
            'body' => $body !== '' ? $body : null,
            'image_path' => $imagePath,
            'created_at' => now(),
        ]);
        $thread->forceFill(['last_message_at' => now()])->save();

        return redirect()->route('school.chat.show', ['school' => $school->slug, 'threadId' => $thread->id]);
    }

    protected function authorizePair(ChatService $chat, User $sender, User $target, Student $student, School $school): void
    {
        $senderIsTeacher = $chat->isTeacherOf($sender, $school, $student);
        $senderIsGuardian = $chat->isGuardianOf($sender, $school, $student);
        $targetIsTeacher = $chat->isTeacherOf($target, $school, $student);
        $targetIsGuardian = $chat->isGuardianOf($target, $school, $student);

        abort_unless(($senderIsTeacher && $targetIsGuardian) || ($senderIsGuardian && $targetIsTeacher), 404);
    }
}
