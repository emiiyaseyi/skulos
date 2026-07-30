<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Section;
use App\Services\Communication\AudienceResolver;
use App\Services\Communication\NotificationService;
use App\Services\Media\ImageProcessingService;
use App\Services\Rbac\Rbac;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;

/**
 * Messages (2026-07-15). A school owner/admin sends a broadcast to a
 * chosen audience (a role group or everyone) and scope (the whole school,
 * one section, or one class). A one-way broadcast is delivered as a
 * sign-in pop-up the recipient acknowledges; this screen then shows the
 * sender how many have acknowledged (read receipts).
 *
 * Audiences and scopes come from config catalogs, never literals (standing
 * "nothing hardcoded" rule). The rich-text body is sanitized through
 * App\Support\HtmlSanitizer before it is ever stored or shown to another
 * user (stored-XSS boundary). Delivery reuses
 * NotificationService::dispatchAnnouncement() via AudienceResolver, not a
 * second delivery path. The {school} route sits under a domain wildcard,
 * so params are read from the request per the CLAUDE.md gotcha.
 *
 * Two-way (chat) messaging is a separate increment; this controller
 * covers the one-way broadcast the majority of school comms needs.
 */
class MessageController extends Controller
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

        $broadcasts = Announcement::broadcasts()
            // A platform-owner broadcast (messaging increment 3) is
            // delivered into this school's tenant DB so its owner/admin get
            // the same sign-in pop-up, but the school did not author it, it
            // must not appear on the school's own "sent" ledger here, see
            // PlatformBroadcastService.
            ->whereNull('platform_broadcast_id')
            ->with('section', 'classArm.schoolClass')
            ->withCount([
                'notifications as delivered_count',
                'notifications as read_count' => fn ($q) => $q->whereNotNull('read_at'),
            ])
            ->orderByDesc('created_at')
            ->paginate(20);

        return view('school.messages.index', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'broadcasts' => $broadcasts,
            'canCompose' => app(Rbac::class)->check(Auth::user(), $school, 'communication.broadcast'),
        ]);
    }

    public function create(Request $request): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'communication.broadcast');
        $school = $this->school($request);

        return view('school.messages.create', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'audiences' => config('skulos.message_audiences'),
            'scopes' => config('skulos.message_scopes'),
            'sections' => Section::orderBy('sequence')->get(),
            'classArms' => ClassArm::with('schoolClass.section', 'arm')->get()
                ->sortBy(fn (ClassArm $arm) => $arm->orderKey())->values(),
        ]);
    }

    public function store(Request $request, NotificationService $notifications, AudienceResolver $resolver, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'communication.broadcast');
        $school = $this->school($request);

        $data = $request->validate([
            'title' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:20000'],
            'audience' => ['required', Rule::in(array_keys(config('skulos.message_audiences')))],
            'scope' => ['required', Rule::in(array_keys(config('skulos.message_scopes')))],
            'section_id' => ['nullable', 'required_if:scope,section', 'exists:tenant.sections,id'],
            'class_arm_id' => ['nullable', 'required_if:scope,class', 'exists:tenant.class_arms,id'],
            // "Light image": capped at 5MB on upload, but the stored file
            // is always the WebP-converted, downsized version regardless
            // of how large the original photo was (a phone camera photo is
            // routinely 3-8MB; nothing close to that is ever kept).
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        // Sanitize the rich-text body before it is stored or shown to any
        // other user. Reject if nothing survives (e.g. only a stripped
        // script), so an "empty" broadcast is never sent.
        $body = HtmlSanitizer::clean($data['body']);
        if (trim(strip_tags($body)) === '') {
            return back()->withInput()->withErrors(['body' => 'The message cannot be empty.']);
        }

        $imagePath = $request->hasFile('image')
            ? $images->toWebp($request->file('image'), "messages/{$school->slug}")
            : null;

        $broadcast = Announcement::create([
            'title' => $data['title'],
            'body' => $body,
            'type' => Announcement::TYPE_BROADCAST,
            'image_path' => $imagePath,
            'audience' => $data['audience'],
            'section_id' => $data['scope'] === 'section' ? $data['section_id'] : null,
            'class_arm_id' => $data['scope'] === 'class' ? $data['class_arm_id'] : null,
            'status' => Announcement::STATUS_DRAFT,
            'created_by_user_id' => Auth::id(),
        ]);

        $recipients = $resolver->resolve(
            $school,
            $data['audience'],
            $data['scope'],
            $data['section_id'] ?? null,
            $data['class_arm_id'] ?? null,
        );
        $result = $notifications->dispatchAnnouncement($broadcast, $recipients);

        return redirect()->route('school.messages.index', ['school' => $school->slug])->with('status',
            "Broadcast sent to {$result['in_app']} recipient(s), {$result['email_queued']} email(s) queued to send.");
    }
}
