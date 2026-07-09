<?php

namespace App\Http\Controllers\School;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Models\School;
use App\Services\Mail\MailSettingsService;
use App\Services\Rbac\Rbac;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * A school's own SMTP settings (user request 2026-07-09), optional — if
 * left blank the platform's default SMTP settings are used instead.
 */
class MailSettingsController extends Controller
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

    public function edit(Request $request, MailSettingsService $mail): \Illuminate\View\View
    {
        $this->authorizeAction($request, 'school_info.view');
        $school = $this->school($request);

        return view('school.mail-settings', [
            'school' => $school,
            'accent' => $school->branding()['color'],
            'settings' => $mail->schoolSettings(),
        ]);
    }

    public function update(Request $request, MailSettingsService $mail): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $data = $request->validate([
            'host' => ['nullable', 'string', 'max:150'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:150'],
            'password' => ['nullable', 'string', 'max:150'],
            'encryption' => ['nullable', 'string', 'in:tls,ssl,'],
            'from_address' => ['nullable', 'email', 'max:150'],
            'from_name' => ['nullable', 'string', 'max:120'],
        ]);

        $mail->saveSchoolSettings($data);

        return back()->with('status', 'Mail settings saved.');
    }

    public function sendTest(Request $request, MailSettingsService $mail): \Illuminate\Http\RedirectResponse
    {
        $this->authorizeAction($request, 'school_info.manage_settings');
        $mail->applySchoolOverride();

        try {
            Mail::to(Auth::user()->email)->send(new TestMail());

            return back()->with('status', 'Test email sent to '.Auth::user()->email.'.');
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not send: '.$e->getMessage());
        }
    }
}
