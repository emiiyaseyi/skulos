<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Jobs\SendPlatformBroadcastEmailJob;
use App\Models\Plan;
use App\Models\PlatformBroadcast;
use App\Models\Role;
use App\Models\School;
use App\Services\Auditing\Recorder;
use App\Services\Communication\PlatformBroadcastService;
use App\Services\Media\ImageProcessingService;
use App\Support\HtmlSanitizer;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * Send Broadcast (Dashboard Design Cross-Check, 2026-07-13: "Blocked, no
 * platform-wide broadcast tool exists"). A message from the SKULOS team to
 * a chosen set of schools' leadership, delivered two ways: an email (queued
 * per recipient via SendPlatformBroadcastEmailJob since 2026-07-30, was
 * inline Mail::send() in a loop across every matched school before that,
 * a real timeout risk on an "all schools" audience) and, since messaging
 * increment 3 (2026-07-15), the same sign-in pop-up + acknowledge
 * mechanism school-level broadcasts use, fanned into each matched school's
 * own tenant database by PlatformBroadcastService so the sender can see
 * who has actually acknowledged it, not just that an email went out.
 *
 * "recipient_group" (which role tier within each matched school) is new in
 * increment 3 and optional: omitting it (as the pre-increment-3 API did)
 * falls back to "leadership", the exact original hardcoded reach, so
 * nothing already relying on this endpoint's old behavior changes.
 */
class BroadcastController extends Controller
{
    public function create(): \Illuminate\View\View
    {
        return view('admin.broadcast', [
            'schools' => School::whereNotNull('provisioned_at')->orderBy('name')->get(),
            'plans' => Plan::orderBy('name')->get(),
            'recipientGroups' => config('skulos.platform_message_audiences'),
            'recent' => PlatformBroadcast::with('creator')->latest()->limit(10)->get(),
        ]);
    }

    public function send(Request $request, PlatformBroadcastService $delivery, ImageProcessingService $images): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'in:all,plan,selected'],
            'plan_id' => ['nullable', 'exists:plans,id'],
            'school_ids' => ['nullable', 'array'],
            'school_ids.*' => ['exists:schools,id'],
            'recipient_group' => ['nullable', 'in:'.implode(',', array_keys(config('skulos.platform_message_audiences')))],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:20000'],
            'image' => ['nullable', 'image', 'mimes:jpg,jpeg,png,webp,gif', 'max:5120'],
        ]);

        $schools = match ($data['audience']) {
            'all' => School::whereNotNull('provisioned_at')->get(),
            'plan' => School::whereNotNull('provisioned_at')->where('plan_id', $data['plan_id'])->get(),
            'selected' => School::whereNotNull('provisioned_at')->whereIn('id', $data['school_ids'] ?? [])->get(),
        };

        if ($schools->isEmpty()) {
            return back()->with('error', 'No schools matched that audience.')->withInput();
        }

        $recipientGroup = $data['recipient_group'] ?? 'leadership';
        $body = HtmlSanitizer::clean($data['body']);
        if (trim(strip_tags($body)) === '') {
            return back()->withInput()->withErrors(['body' => 'The message cannot be empty.']);
        }
        $imagePath = $request->hasFile('image')
            ? $images->toWebp($request->file('image'), 'platform-broadcasts')
            : null;

        $roleSlugs = config("skulos.platform_message_audience_roles.{$recipientGroup}", []);
        $roleIds = Role::whereIn('slug', $roleSlugs)->pluck('id');
        $queued = 0;
        $emailSchoolsReached = 0;

        foreach ($schools as $school) {
            $recipients = $school->users()->wherePivot('status', 'active')
                ->whereIn('role_id', $roleIds)
                ->whereNotNull('email')
                ->get();

            if ($recipients->isEmpty()) {
                continue;
            }

            foreach ($recipients as $recipient) {
                SendPlatformBroadcastEmailJob::dispatch($recipient->email, $data['subject'], $body, $school->name);
                $queued++;
            }
            $emailSchoolsReached++;
        }

        $broadcast = PlatformBroadcast::create([
            'title' => $data['subject'],
            'body' => $body,
            'image_path' => $imagePath,
            'recipient_group' => $recipientGroup,
            'school_scope' => $data['audience'],
            'plan_id' => $data['plan_id'] ?? null,
            'created_by_user_id' => Auth::id(),
        ]);
        $popupResult = $delivery->deliver($broadcast, $schools);

        app(Recorder::class)->record('platform.broadcast_sent', $broadcast, [], [
            'subject' => $data['subject'], 'audience' => $data['audience'], 'recipient_group' => $recipientGroup,
            'schools_reached' => $emailSchoolsReached, 'emails_queued' => $queued,
            'popup_schools_reached' => $popupResult['schools_reached'], 'popup_recipients' => $popupResult['recipients_reached'],
        ]);

        return redirect()->route('admin.dashboard')->with('status',
            "Broadcast queued to {$queued} recipient(s) across {$emailSchoolsReached} school(s) by email, and delivered as a sign-in pop-up to {$popupResult['recipients_reached']} recipient(s).");
    }
}
