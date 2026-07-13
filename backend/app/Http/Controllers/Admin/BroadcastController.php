<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\PlatformBroadcastMail;
use App\Models\Plan;
use App\Models\Role;
use App\Models\School;
use App\Services\Auditing\Recorder;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;

/**
 * Send Broadcast (Dashboard Design Cross-Check, 2026-07-13: "Blocked, no
 * platform-wide broadcast tool exists"). A message from the SKULOS team to
 * a chosen set of schools' leadership (owner/admin tier), delivered by
 * email through the existing branded transactional layout. At today's real
 * scale (a handful of schools) this sends synchronously, the same
 * un-queued pattern trial request emails already use; revisit with a
 * queued job if the school count ever makes that slow.
 */
class BroadcastController extends Controller
{
    public function create(): \Illuminate\View\View
    {
        return view('admin.broadcast', [
            'schools' => School::whereNotNull('provisioned_at')->orderBy('name')->get(),
            'plans' => Plan::orderBy('name')->get(),
        ]);
    }

    public function send(Request $request): \Illuminate\Http\RedirectResponse
    {
        $data = $request->validate([
            'audience' => ['required', 'in:all,plan,selected'],
            'plan_id' => ['nullable', 'exists:plans,id'],
            'school_ids' => ['nullable', 'array'],
            'school_ids.*' => ['exists:schools,id'],
            'subject' => ['required', 'string', 'max:150'],
            'body' => ['required', 'string', 'max:5000'],
        ]);

        $schools = match ($data['audience']) {
            'all' => School::whereNotNull('provisioned_at')->get(),
            'plan' => School::whereNotNull('provisioned_at')->where('plan_id', $data['plan_id'])->get(),
            'selected' => School::whereNotNull('provisioned_at')->whereIn('id', $data['school_ids'] ?? [])->get(),
        };

        if ($schools->isEmpty()) {
            return back()->with('error', 'No schools matched that audience.')->withInput();
        }

        $leadershipRoleIds = Role::whereIn('slug', [...config('skulos.leadership_role_slugs'), 'school-admin'])->pluck('id');
        $sent = 0;
        $schoolsReached = 0;

        foreach ($schools as $school) {
            $recipients = $school->users()->wherePivot('status', 'active')
                ->whereIn('role_id', $leadershipRoleIds)
                ->whereNotNull('email')
                ->get();

            if ($recipients->isEmpty()) {
                continue;
            }

            foreach ($recipients as $recipient) {
                Mail::to($recipient->email)->send(new PlatformBroadcastMail($data['subject'], $data['body'], $school->name));
                $sent++;
            }
            $schoolsReached++;
        }

        app(Recorder::class)->record('platform.broadcast_sent', null, [], [
            'subject' => $data['subject'], 'audience' => $data['audience'],
            'schools_reached' => $schoolsReached, 'emails_sent' => $sent,
        ]);

        return redirect()->route('admin.dashboard')->with('status',
            "Broadcast sent to {$sent} recipient(s) across {$schoolsReached} school(s).");
    }
}
