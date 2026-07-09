<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TestMail;
use App\Services\Mail\MailSettingsService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Platform wide SMTP settings (user request 2026-07-09: "allow me to
 * setup SMTP server settings for the platform"). A school can also set
 * its own (School\MailSettingsController); that wins over this default
 * while a request is scoped to that school.
 */
class MailSettingsController extends Controller
{
    public function edit(MailSettingsService $mail): \Illuminate\View\View
    {
        return view('admin.mail-settings', ['settings' => $mail->platformSettings()]);
    }

    public function update(Request $request, MailSettingsService $mail): \Illuminate\Http\RedirectResponse
    {
        $data = $this->validated($request);
        $mail->savePlatformSettings($data);

        return back()->with('status', 'Platform mail settings saved.');
    }

    public function sendTest(MailSettingsService $mail): \Illuminate\Http\RedirectResponse
    {
        $mail->applyPlatformDefaults();

        try {
            Mail::to(Auth::user()->email)->send(new TestMail());

            return back()->with('status', 'Test email sent to '.Auth::user()->email.'. Check your inbox (and spam folder).');
        } catch (\Throwable $e) {
            return back()->with('error', 'Could not send: '.$e->getMessage());
        }
    }

    protected function validated(Request $request): array
    {
        return $request->validate([
            'host' => ['nullable', 'string', 'max:150'],
            'port' => ['nullable', 'integer', 'min:1', 'max:65535'],
            'username' => ['nullable', 'string', 'max:150'],
            'password' => ['nullable', 'string', 'max:150'],
            'encryption' => ['nullable', 'string', 'in:tls,ssl,'],
            'from_address' => ['nullable', 'email', 'max:150'],
            'from_name' => ['nullable', 'string', 'max:120'],
        ]);
    }
}
