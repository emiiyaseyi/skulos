<?php

namespace Tests\Feature;

use App\Mail\ContactFormMail;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class ContactFormTest extends TestCase
{
    // Needed since 2026-08-16: ContactFormMail's envelope() now checks
    // for an edited template (Settings > Mail Templates), which queries
    // the platform database even under Mail::fake() -- the fake
    // intercepts the actual send, not envelope()/content(), and the
    // first test below calls envelope() directly in its own assertion.
    use RefreshDatabase;

    public function test_a_visitor_can_submit_the_contact_form_and_an_email_is_sent(): void
    {
        Mail::fake();

        $response = $this->post('/api/contact', [
            'name' => 'Ada Lovelace',
            'school' => 'Greenfield Academy',
            'email' => 'ada@example.com',
            'message' => 'Interested in the Advance plan.',
        ]);

        $response->assertRedirect('/contact.html?sent=1');

        Mail::assertSent(ContactFormMail::class, function (ContactFormMail $mail) {
            return $mail->hasTo(config('skulos.support.email'))
                && $mail->envelope()->replyTo[0]->address === 'ada@example.com';
        });
    }

    public function test_the_honeypot_field_silently_drops_a_bot_submission(): void
    {
        Mail::fake();

        $response = $this->post('/api/contact', [
            'name' => 'Bot',
            'school' => 'Bot School',
            'email' => 'bot@example.com',
            'website' => 'https://spam.example.com',
        ]);

        $response->assertRedirect('/contact.html?sent=1');
        Mail::assertNothingSent();
    }

    public function test_missing_required_fields_are_rejected(): void
    {
        Mail::fake();

        $response = $this->post('/api/contact', ['name' => 'Ada']);

        $response->assertSessionHasErrors(['school', 'email']);
        Mail::assertNothingSent();
    }
}
