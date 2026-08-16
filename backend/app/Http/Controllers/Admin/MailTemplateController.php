<?php

namespace App\Http\Controllers\Admin;

use App\Http\Controllers\Controller;
use App\Mail\TemplatePreviewMail;
use App\Models\MailTemplate;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Mail;

/**
 * Edit the wording of every email the platform sends (2026-08-16).
 *
 * Each message previously lived inside its Mailable, so changing a
 * sentence meant a code change and a deploy. The catalog in
 * config('skulos.mail_templates') says which mails exist and which
 * placeholders each may use; a row in mail_templates holds whatever has
 * been edited; the Mailable's own code stays the fallback.
 *
 * That fallback direction is deliberate: an unedited mail keeps working
 * with no row at all, a template can be reverted by deleting it, and a
 * mail added later cannot start sending blank because nobody wrote a
 * template for it yet.
 */
class MailTemplateController extends Controller
{
    public function index(): \Illuminate\View\View
    {
        $catalog = config('skulos.mail_templates', []);
        $edited = MailTemplate::with('updatedBy')->get()->keyBy('key');

        return view('admin.mail-templates.index', [
            'catalog' => $catalog,
            'edited' => $edited,
        ]);
    }

    public function edit(Request $request): \Illuminate\View\View
    {
        $key = $request->route('key');
        $catalog = config('skulos.mail_templates', []);
        abort_unless(isset($catalog[$key]), 404);

        $template = MailTemplate::where('key', $key)->first();

        return view('admin.mail-templates.edit', [
            'key' => $key,
            'meta' => $catalog[$key],
            'template' => $template,
            // Seeded from what this mail says today, so the editor opens
            // on the real wording rather than an empty box.
            'subject' => $template->subject ?? $catalog[$key]['seed_subject'],
            'body' => $template->body ?? $catalog[$key]['seed_body'],
            'isEdited' => (bool) $template,
        ]);
    }

    public function update(Request $request): \Illuminate\Http\RedirectResponse
    {
        $key = $request->route('key');
        $catalog = config('skulos.mail_templates', []);
        abort_unless(isset($catalog[$key]), 404);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        MailTemplate::updateOrCreate(
            ['key' => $key],
            $data + ['updated_by_user_id' => Auth::id()],
        );

        return back()->with('status', 'Saved. New messages of this type will use your wording.');
    }

    /** Back to the built-in wording, by removing the override entirely. */
    public function reset(Request $request): \Illuminate\Http\RedirectResponse
    {
        $key = $request->route('key');
        MailTemplate::where('key', $key)->delete();

        return back()->with('status', 'Reverted to the built-in wording.');
    }

    /**
     * Sends the template to the signed-in admin with the placeholders
     * filled by sample values, so wording can be checked in a real
     * inbox before a school ever receives it.
     */
    public function test(Request $request): \Illuminate\Http\RedirectResponse
    {
        $key = $request->route('key');
        $catalog = config('skulos.mail_templates', []);
        abort_unless(isset($catalog[$key]), 404);

        $data = $request->validate([
            'subject' => ['required', 'string', 'max:500'],
            'body' => ['required', 'string', 'max:20000'],
        ]);

        // Sample values, not real ones: a test send must never reach out
        // with a genuine school's details or a real temporary password.
        $samples = [];
        foreach ($catalog[$key]['placeholders'] as $placeholder) {
            $samples[$placeholder] = match ($placeholder) {
                'school_name' => 'Bright Star Academy',
                'contact_name', 'name', 'applicant_name', 'sender_name' => 'Ada Okoro',
                'portal_url', 'reset_url' => 'https://brightstar.'.config('app.domain'),
                'demo_url' => config('skulos.demo.url'),
                'login_email', 'sender_email' => 'admin@example.com',
                'temporary_password' => 'SampleOnly123',
                'plan_name' => 'Advance',
                'class_name' => 'JSS 1',
                'reference_number' => 'APP/0001',
                'sender_school' => 'Bright Star Academy',
                'sender_message' => 'This is a sample message.',
                default => strtoupper($placeholder),
            };
        }

        Mail::to(Auth::user()->email)->send(new TemplatePreviewMail(
            MailTemplate::substitute($data['subject'], $samples),
            nl2br(MailTemplate::substitute($data['body'], $samples)),
        ));

        return back()->with('status', 'Test email sent to '.Auth::user()->email.' using sample values.');
    }
}
