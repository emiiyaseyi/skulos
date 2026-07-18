<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Communication\ChatService;
use App\Services\Communication\CommunicationService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A school's own communication settings: teacher-parent messaging
 * (messaging increment 4, 2026-07-15, user directive: "NOTE THAT ADMIN CAN
 * TURN OFF TEACHERS MESSAGE TO PARENTS") and, since 2026-07-18, whether
 * announcement email delivery is on (Advance plan feature 'email_
 * notifications' — the delivery itself, CommunicationService::
 * emailAnnouncement()/AnnouncementController::store(), already existed
 * and worked, this school-facing on/off switch did not).
 */
class CommunicationSettingsController extends Controller
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

    public function edit(Request $request, ChatService $chat, CommunicationService $comms): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.view');
        $school = $this->school($request);

        return view('school.settings-communication', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'teacherParentMessagingEnabled' => $chat->teacherParentMessagingEnabled(),
            'emailNotificationsEnabled' => $comms->channelEnabled('email'),
        ]);
    }

    public function update(Request $request, ChatService $chat, CommunicationService $comms): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');

        $chat->setTeacherParentMessagingEnabled($request->boolean('teacher_parent_messaging_enabled'));
        $comms->setEmailChannelEnabled($request->boolean('email_notifications_enabled'));

        return back()->with('status', 'Communication settings saved.');
    }
}
