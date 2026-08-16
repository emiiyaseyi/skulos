<?php

namespace Tests\Feature;

use App\Mail\AccountCreatedMail;
use App\Mail\PasswordResetByAdminMail;
use App\Models\MailTemplate;
use App\Models\School;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Editable mail wording (2026-08-16, direct instruction: "there should
 * be a page to edit all the formats of mails going out and use the
 * dynamic function in the body of the mail as no mail is static").
 *
 * The two things worth locking down are the two that would go wrong
 * quietly: an edit that does not actually reach the sent message, and an
 * edit that takes the temporary password with it.
 */
class MailTemplateEditorTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function admin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_an_unedited_mail_keeps_its_built_in_wording(): void
    {
        $school = School::factory()->create(['name' => 'Bright Star']);
        $user = User::factory()->create(['name' => 'Ada Okoro']);

        $rendered = (new PasswordResetByAdminMail($user, $school, 'TempPass123'))->render();

        // No row exists, so nothing about this mail should have changed.
        $this->assertStringContainsString('Hello Ada Okoro', $rendered);
        $this->assertStringContainsString('TempPass123', $rendered);
    }

    public function test_an_edit_changes_the_wording_that_actually_sends(): void
    {
        $school = School::factory()->create(['name' => 'Bright Star']);
        $user = User::factory()->create(['name' => 'Ada Okoro']);

        MailTemplate::create([
            'key' => 'PasswordResetByAdminMail',
            'subject' => 'Password reset for {school_name}',
            'body' => "Good day {name},\n\nYour {school_name} password was changed for you.",
        ]);

        $mail = new PasswordResetByAdminMail($user, $school, 'TempPass123');
        $rendered = $mail->render();

        $this->assertSame('Password reset for Bright Star', $mail->envelope()->subject);
        $this->assertStringContainsString('Good day Ada Okoro', $rendered);
        $this->assertStringContainsString('Your Bright Star password was changed for you.', $rendered);
        // The wording it replaced is genuinely gone, not merely added to.
        $this->assertStringNotContainsString('was just reset by a platform administrator', $rendered);
    }

    public function test_editing_the_wording_never_drops_the_sign_in_details(): void
    {
        // A real provisioned school: this mail reads the role brief out
        // of the tenant database, so it needs a tenant connection.
        $school = app(TenantManager::class)->provision(
            School::factory()->create(['name' => 'Bright Star', 'slug' => 'brightstar'])
        );
        $user = User::factory()->create(['name' => 'Ada Okoro', 'email' => 'ada@example.com']);

        // Wording with no placeholders at all: the worst case for an
        // admin accidentally deleting the password from their own email.
        MailTemplate::create([
            'key' => 'AccountCreatedMail',
            'subject' => 'Your account',
            'body' => 'Welcome aboard.',
        ]);

        $rendered = app(TenantManager::class)->connectTo($school, fn () => (new AccountCreatedMail(
            $user, $school, 'TempPass123', 'Class Teacher', 'class-teacher'
        ))->render());

        $this->assertStringContainsString('Welcome aboard.', $rendered);
        // The details box is built by the Mailable, not the template,
        // so a temporary password can never be edited away by accident.
        $this->assertStringContainsString('TempPass123', $rendered);
        $this->assertStringContainsString('ada@example.com', $rendered);
    }

    public function test_an_unknown_placeholder_stays_visible_rather_than_vanishing(): void
    {
        $school = School::factory()->create(['name' => 'Bright Star']);
        $user = User::factory()->create(['name' => 'Ada Okoro']);

        MailTemplate::create([
            'key' => 'PasswordResetByAdminMail',
            'subject' => 'Reset',
            'body' => 'Hello {nmae}, welcome.',
        ]);

        // A typo must show up as itself, so it is caught in a test send,
        // rather than leaving a hole in the middle of a sentence.
        $this->assertStringContainsString('{nmae}', (new PasswordResetByAdminMail($user, $school, 'x'))->render());
    }

    public function test_only_a_platform_admin_can_edit_the_wording(): void
    {
        $this->actingAs(User::factory()->create())
            ->get('/admin/settings/mail-templates')
            // 404 rather than 403: the platform admin area never
            // confirms a screen exists to someone without access.
            ->assertNotFound();
    }

    public function test_an_admin_saves_and_then_reverts_a_template(): void
    {
        $admin = $this->admin();

        $this->actingAs($admin)
            ->post('/admin/settings/mail-templates/PasswordResetByAdminMail', [
                'subject' => 'Edited subject',
                'body' => 'Edited body',
            ])->assertRedirect();

        $this->assertDatabaseHas('mail_templates', [
            'key' => 'PasswordResetByAdminMail',
            'subject' => 'Edited subject',
            'updated_by_user_id' => $admin->id,
        ]);

        $this->actingAs($admin)
            ->post('/admin/settings/mail-templates/PasswordResetByAdminMail/reset')
            ->assertRedirect();

        // Reverting removes the row entirely, so the code default is
        // what stands again rather than a saved copy of it.
        $this->assertDatabaseMissing('mail_templates', ['key' => 'PasswordResetByAdminMail']);
    }

    public function test_a_mail_that_is_not_in_the_catalog_cannot_be_edited(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/settings/mail-templates/NotARealMail')
            ->assertNotFound();
    }

    public function test_the_editor_opens_on_the_wording_the_mail_uses_today(): void
    {
        $this->actingAs($this->admin())
            ->get('/admin/settings/mail-templates/TrialRequestStatusMail')
            ->assertOk()
            // Seeded from the real letter, so an admin edits what schools
            // actually receive rather than starting from an empty box.
            ->assertSee('Thank you for your interest in SKULOS')
            ->assertSee('{school_name}');
    }

    public function test_the_trial_letter_seed_matches_the_full_real_letter_not_a_summary(): void
    {
        // Direct report (2026-08-16): the editor showed "an abbreviated
        // version" of the trial and welcome letters. The real letter has
        // seven "## " sections of setup information; a seed missing them
        // would silently let an edit ship a shorter letter than before.
        foreach (['TrialRequestStatusMail', 'SchoolWelcomeMail'] as $key) {
            $seed = config("skulos.mail_templates.{$key}.seed_body");

            foreach ([
                'School Information', 'Academic Structure', 'Student Information',
                'Staff Information', 'Assessment and Results', 'Parents and Communication',
                'Other Information',
            ] as $section) {
                $this->assertStringContainsString("## {$section}", $seed, "{$key} is missing the {$section} section.");
            }
        }
    }

    public function test_headings_and_bullet_lists_render_as_real_html_not_literal_text(): void
    {
        $rendered = MailTemplate::renderBody("Dear Ada,\n\n## School Information\n- Logo\n- Address\n\nThanks.");

        $this->assertStringContainsString('<p style="margin:22px 0 8px;font-size:15px;font-weight:bold;color:#0B1420;">School Information</p>', $rendered);
        $this->assertStringContainsString('<ul style="margin:0 0 14px;padding-left:20px;"><li style="margin:0 0 4px;line-height:20px;">Logo</li><li style="margin:0 0 4px;line-height:20px;">Address</li></ul>', $rendered);
        $this->assertStringContainsString('<p style="margin:0 0 14px;line-height:22px;">Dear Ada,</p>', $rendered);
        // The markup itself is never left visible in the output.
        $this->assertStringNotContainsString('## ', $rendered);
        $this->assertStringNotContainsString('- Logo', $rendered);
    }

    public function test_heading_and_list_text_is_escaped_not_executed(): void
    {
        $rendered = MailTemplate::renderBody('## <script>alert(1)</script>');

        $this->assertStringNotContainsString('<script>alert(1)</script>', $rendered);
        $this->assertStringContainsString('&lt;script&gt;', $rendered);
    }

    public function test_the_test_send_preview_renders_the_same_markup_as_a_real_send(): void
    {
        $admin = $this->admin();

        MailTemplate::create([
            'key' => 'PasswordResetByAdminMail',
            'subject' => 'Reset',
            'body' => "Hello {name},\n\n## Next steps\n- Sign in\n- Change your password",
        ]);

        \Illuminate\Support\Facades\Mail::fake();

        $this->actingAs($admin)->post('/admin/settings/mail-templates/PasswordResetByAdminMail/test', [
            'subject' => 'Reset',
            'body' => "Hello {name},\n\n## Next steps\n- Sign in\n- Change your password",
        ])->assertRedirect();

        \Illuminate\Support\Facades\Mail::assertSent(\App\Mail\TemplatePreviewMail::class, function ($mail) {
            return str_contains($mail->renderedBody, '<li style="margin:0 0 4px;line-height:20px;">Sign in</li>');
        });
    }
}
