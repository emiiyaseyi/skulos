<?php

namespace Tests\Feature;

use App\Models\PlatformBroadcast;
use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Models\Tenant\Notification;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Messaging increment 3 (2026-07-15): a platform-owner broadcast is
 * delivered two ways: email (pre-existing, 2026-07-13) and, new here, the
 * same sign-in pop-up + acknowledge mechanism school-level broadcasts use
 * (increment 1), fanned into each matched school's own tenant database.
 * "recipient_group" narrows WHO within a school ("THE PLATFORM OWNER CAN
 * SEND BROADCAST... TO ALL SCHOOLS OWNSERS, ADMIN"), separate from the
 * pre-existing "audience" field which narrows WHICH schools.
 */
class PlatformBroadcastPopupTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected Rbac $rbac;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        $this->seed(RbacSeeder::class);
        $this->rbac = app(Rbac::class);
        Storage::fake('public');
        Mail::fake();
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    /** @return array{0: School, 1: User, 2: User, 3: User} school, owner, admin, teacher */
    protected function schoolWithLeadership(string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $owner = User::factory()->create(['email' => "owner-{$slug}@example.com"]);
        $school->users()->attach($owner->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'school-owner')->id, 'status' => 'active']);
        $admin = User::factory()->create(['email' => "admin-{$slug}@example.com"]);
        $school->users()->attach($admin->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'school-admin')->id, 'status' => 'active']);
        $teacher = User::factory()->create(['email' => "teacher-{$slug}@example.com"]);
        $school->users()->attach($teacher->id, ['role_id' => $this->rbac->cloneTemplateRole($school, 'teacher')->id, 'status' => 'active']);

        return [$school, $owner, $admin, $teacher];
    }

    public function test_a_broadcast_creates_a_platform_record_and_a_real_tenant_announcement(): void
    {
        $admin = $this->superAdmin();
        [$school, $owner] = $this->schoolWithLeadership('popone');

        $this->actingAs($admin)->post(route('admin.broadcast.send'), [
            'audience' => 'all', 'recipient_group' => 'owners_and_admins',
            'subject' => 'Platform update', 'body' => '<p>New features live.</p>',
        ])->assertRedirect(route('admin.dashboard'));

        $broadcast = PlatformBroadcast::first();
        $this->assertNotNull($broadcast);
        $this->assertSame(1, $broadcast->schools_reached);
        $this->assertSame(2, $broadcast->recipients_reached); // owner + admin, not teacher

        app(TenantManager::class)->use($school);
        $announcement = Announcement::where('platform_broadcast_id', $broadcast->id)->first();
        $this->assertNotNull($announcement);
        $this->assertSame(2, $announcement->notifications()->count());
        app(TenantManager::class)->forget();
    }

    public function test_owners_only_recipient_group_excludes_admins(): void
    {
        $admin = $this->superAdmin();
        [$school, $owner, $schoolAdmin] = $this->schoolWithLeadership('poptwo');

        $this->actingAs($admin)->post(route('admin.broadcast.send'), [
            'audience' => 'all', 'recipient_group' => 'owners',
            'subject' => 'Owners only', 'body' => '<p>Just for owners.</p>',
        ]);

        $broadcast = PlatformBroadcast::first();
        $this->assertSame(1, $broadcast->recipients_reached);

        Mail::assertSent(\App\Mail\PlatformBroadcastMail::class, fn ($mail) => $mail->hasTo($owner->email));
        Mail::assertNotSent(\App\Mail\PlatformBroadcastMail::class, fn ($mail) => $mail->hasTo($schoolAdmin->email));
    }

    public function test_omitting_recipient_group_falls_back_to_the_original_leadership_reach(): void
    {
        $admin = $this->superAdmin();
        [$school, $owner] = $this->schoolWithLeadership('popthree');

        $this->actingAs($admin)->post(route('admin.broadcast.send'), [
            'audience' => 'all', 'subject' => 'No group specified', 'body' => 'Plain text body.',
        ])->assertRedirect();

        $broadcast = PlatformBroadcast::first();
        $this->assertSame('leadership', $broadcast->recipient_group);
    }

    public function test_the_recipient_sees_the_broadcast_popup_and_acknowledging_marks_it_read_for_the_platform_sender(): void
    {
        $admin = $this->superAdmin();
        [$school, $owner] = $this->schoolWithLeadership('popfour');

        $this->actingAs($admin)->post(route('admin.broadcast.send'), [
            'audience' => 'all', 'recipient_group' => 'owners_and_admins',
            'subject' => 'Please read', 'body' => '<p>Important notice.</p>',
        ]);
        $broadcast = PlatformBroadcast::first();

        // The recipient's next page load in their school portal shows the pop-up.
        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()->assertSee('Please read');

        app(TenantManager::class)->use($school);
        $notification = Notification::forUser($owner->id)->where('category', 'broadcast')->firstOrFail();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.notifications.read', ['school' => $school->slug, 'id' => $notification->id]))
            ->assertRedirect();

        $stats = app(\App\Services\Communication\PlatformBroadcastService::class)->stats($broadcast, School::whereNotNull('provisioned_at')->get());
        $this->assertSame(2, $stats['delivered']); // owner + admin
        $this->assertSame(1, $stats['read']); // only the owner acknowledged
    }

    public function test_a_platform_broadcast_does_not_appear_on_the_schools_own_sent_messages_list(): void
    {
        $admin = $this->superAdmin();
        [$school, $owner] = $this->schoolWithLeadership('popfive');

        $this->actingAs($admin)->post(route('admin.broadcast.send'), [
            'audience' => 'all', 'recipient_group' => 'owners',
            'subject' => 'Not the schools own message', 'body' => '<p>Body.</p>',
        ]);

        // Acknowledge the sign-in pop-up first so the assertion below is
        // actually about the "sent messages" list, not the still-pending
        // pop-up (which correctly shows on every page, including this one,
        // until acknowledged).
        app(TenantManager::class)->use($school);
        $notification = Notification::forUser($owner->id)->where('category', 'broadcast')->firstOrFail();
        app(TenantManager::class)->forget();
        $this->actingAs($owner)->post(route('school.notifications.read', ['school' => $school->slug, 'id' => $notification->id]));

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/messages");
        $response->assertOk()->assertDontSee('Not the schools own message');
    }

    public function test_a_light_image_is_converted_to_webp_and_appears_in_the_pop_up(): void
    {
        $admin = $this->superAdmin();
        [$school, $owner] = $this->schoolWithLeadership('popsix');
        $image = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $this->actingAs($admin)->post(route('admin.broadcast.send'), [
            'audience' => 'all', 'recipient_group' => 'owners',
            'subject' => 'With a photo', 'body' => '<p>See attached.</p>', 'image' => $image,
        ]);

        $broadcast = PlatformBroadcast::first();
        $this->assertStringEndsWith('.webp', $broadcast->image_path);
        Storage::disk('public')->assertExists($broadcast->image_path);

        $response = $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard");
        $response->assertOk()->assertSee('bc-image', false);
    }
}
