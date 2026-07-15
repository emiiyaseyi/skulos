<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Models\School;
use App\Services\Communication\ChatService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;

/**
 * A school's own communication settings. Just the one toggle for now
 * (messaging increment 4, 2026-07-15, user directive: "NOTE THAT ADMIN CAN
 * TURN OFF TEACHERS MESSAGE TO PARENTS"); a natural home for any future
 * per-school communication switch, not a one-off page bolted elsewhere.
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

    public function edit(Request $request, ChatService $chat): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.view');
        $school = $this->school($request);

        return view('school.settings-communication', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'teacherParentMessagingEnabled' => $chat->teacherParentMessagingEnabled(),
        ]);
    }

    public function update(Request $request, ChatService $chat): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');

        $chat->setTeacherParentMessagingEnabled($request->boolean('teacher_parent_messaging_enabled'));

        return back()->with('status', 'Communication settings saved.');
    }
}
