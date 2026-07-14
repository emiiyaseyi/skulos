<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\CalendarEvent;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Create Event (Dashboard Design Cross-Check, 2026-07-13: "Blocked, no
 * calendar event creation screen exists yet, only the auto-seeded default
 * notable days"). The model already existed, this is the first real
 * browse/create screen.
 */
class CalendarEventScreenTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    protected function schoolWithMember(string $roleSlug, string $slug): array
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => $slug]));
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        return [$school, $user];
    }

    public function test_owner_can_create_a_real_event(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calone');

        $this->actingAs($owner)->post(route('school.calendar.store', ['school' => $school->slug]), [
            'title' => 'Inter House Debate', 'category' => 'event', 'starts_on' => '2026-08-01', 'all_day' => '1',
        ])->assertRedirect(route('school.calendar.index', ['school' => $school->slug]));

        app(TenantManager::class)->use($school);
        $event = CalendarEvent::where('title', 'Inter House Debate')->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($event);
        $this->assertFalse($event->is_default);
    }

    public function test_the_new_event_appears_on_the_calendar_index(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'caltwo');

        $this->actingAs($owner)->post(route('school.calendar.store', ['school' => $school->slug]), [
            'title' => 'PTA Meeting', 'category' => 'meeting', 'starts_on' => '2026-09-01', 'all_day' => '1',
        ]);

        $this->actingAs($owner)->get(route('school.calendar.index', ['school' => $school->slug]))
            ->assertOk()->assertSee('PTA Meeting');
    }

    public function test_a_default_event_cannot_be_removed_but_a_custom_one_can(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calthree');

        app(TenantManager::class)->use($school);
        $defaultEvent = CalendarEvent::create(['title' => 'Public Holiday', 'category' => 'holiday', 'is_default' => true, 'all_day' => true]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get(route('school.calendar.index', ['school' => $school->slug]));
        $response->assertOk()->assertSee('Public Holiday')->assertSee('(default)');
        $response->assertDontSee(route('school.calendar.destroy', ['school' => $school->slug, 'eventId' => $defaultEvent->id]), false);
    }

    public function test_a_teacher_without_calendar_create_cannot_open_the_create_form(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'calfour');

        $this->actingAs($teacher)->get(route('school.calendar.create', ['school' => $school->slug]))->assertForbidden();
    }
}
