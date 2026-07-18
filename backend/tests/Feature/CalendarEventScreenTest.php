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

    /**
     * "EACH CALENDAR DATE CAN BE CLICKED AND EDITED" (user directive
     * 2026-07-15). A default event's date is often null until a school
     * fills it in, editing must work for those too, not just custom ones.
     */
    public function test_a_default_events_date_can_be_set_via_the_edit_screen(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'caleditone');

        app(TenantManager::class)->use($school);
        $event = CalendarEvent::create(['title' => 'Mid Term Break', 'category' => 'break', 'is_default' => true, 'all_day' => true]);
        $eventId = $event->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get(route('school.calendar.edit', ['school' => $school->slug, 'eventId' => $eventId]))
            ->assertOk()->assertSee('Mid Term Break');

        $this->actingAs($owner)->post(route('school.calendar.update', ['school' => $school->slug, 'eventId' => $eventId]), [
            'title' => 'Mid Term Break', 'category' => 'break', 'starts_on' => '2026-10-12', 'all_day' => '1',
        ])->assertRedirect(route('school.calendar.index', ['school' => $school->slug]));

        app(TenantManager::class)->use($school);
        $updated = CalendarEvent::find($eventId);
        app(TenantManager::class)->forget();
        $this->assertSame('2026-10-12', $updated->starts_on->toDateString());
    }

    public function test_a_teacher_without_calendar_create_cannot_open_the_edit_form(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'caleditwo');

        app(TenantManager::class)->use($school);
        $event = CalendarEvent::create(['title' => 'Christmas Day', 'category' => 'holiday', 'is_default' => true, 'starts_on' => '2026-12-25']);
        $eventId = $event->id;
        app(TenantManager::class)->forget();

        $this->actingAs($teacher)->get(route('school.calendar.edit', ['school' => $school->slug, 'eventId' => $eventId]))
            ->assertForbidden();
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
        // Checking for the exact destroy-form action (quoted), not just the
        // bare URL, since the destroy URL is now also a substring of this
        // same event's edit link (".../calendar/{id}/edit").
        $response->assertDontSee('action="'.route('school.calendar.destroy', ['school' => $school->slug, 'eventId' => $defaultEvent->id]).'"', false);
    }

    /**
     * Audit finding L-1 (2026-07-17): destroy() previously typed $eventId
     * as a scalar method parameter, which this route's domain wildcard
     * ({school}.<domain>) caused Laravel to bind positionally instead of
     * by name, silently receiving the school slug instead of the event
     * id, so this delete always 404'd. The prior test above only checked
     * that a DEFAULT event's destroy form doesn't render, never that a
     * real custom event's destroy action actually works.
     */
    public function test_a_custom_event_can_actually_be_deleted(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calnine');

        app(TenantManager::class)->use($school);
        $event = CalendarEvent::create(['title' => 'Sports Day', 'category' => 'other', 'is_default' => false, 'all_day' => true]);
        $eventId = $event->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->delete(route('school.calendar.destroy', ['school' => $school->slug, 'eventId' => $eventId]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertNull(CalendarEvent::find($eventId));
        app(TenantManager::class)->forget();
    }

    public function test_a_teacher_without_calendar_create_cannot_open_the_create_form(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'calfour');

        $this->actingAs($teacher)->get(route('school.calendar.create', ['school' => $school->slug]))->assertForbidden();
    }

    public function test_an_exam_event_can_be_scheduled_with_a_subject_and_class(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calfive');

        app(TenantManager::class)->use($school);
        $section = \App\Models\Tenant\Section::create(['name' => 'Primary', 'sequence' => 1]);
        $class = \App\Models\Tenant\SchoolClass::create(['section_id' => $section->id, 'name' => 'Primary 3', 'sequence' => 1]);
        $arm = \App\Models\Tenant\ClassArm::create(['school_class_id' => $class->id]);
        $subject = \App\Models\Tenant\Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.calendar.store', ['school' => $school->slug]), [
            'title' => 'First Term Maths Exam', 'category' => 'exam', 'starts_on' => now()->addDays(10)->toDateString(),
            'all_day' => '1', 'subject_id' => $subject->id, 'class_arm_id' => $arm->id,
        ])->assertRedirect(route('school.calendar.index', ['school' => $school->slug]));

        app(TenantManager::class)->use($school);
        $event = CalendarEvent::where('title', 'First Term Maths Exam')->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($event);
        $this->assertSame($subject->id, $event->subject_id);
        $this->assertSame($arm->id, $event->class_arm_id);
    }

    public function test_subject_and_class_are_ignored_for_a_non_exam_category(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calsix');

        app(TenantManager::class)->use($school);
        $subject = \App\Models\Tenant\Subject::create(['name' => 'Mathematics', 'code' => 'MTH']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.calendar.store', ['school' => $school->slug]), [
            'title' => 'PTA Meeting', 'category' => 'meeting', 'starts_on' => now()->addDays(5)->toDateString(),
            'all_day' => '1', 'subject_id' => $subject->id,
        ]);

        app(TenantManager::class)->use($school);
        $event = CalendarEvent::where('title', 'PTA Meeting')->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($event);
        $this->assertNull($event->subject_id, 'subject_id must be discarded for a category other than exam');
    }

    /**
     * User report 2026-07-16: "calendar should be for the current year
     * only it should also show the current term too but one can have the
     * option to switch to another term among the three terms we have".
     * Defaults to the current term's own window, with a switcher over its
     * session's three terms, and undated placeholders still show
     * regardless of which term is selected.
     */
    public function test_the_index_defaults_to_the_current_term_and_can_switch_between_terms(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'calseven');

        app(TenantManager::class)->use($school);
        $session = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026 Session']);
        $first = \App\Models\Tenant\Term::create([
            'academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1,
            'starts_on' => '2025-09-15', 'ends_on' => '2025-12-19',
        ]);
        $second = \App\Models\Tenant\Term::create([
            'academic_session_id' => $session->id, 'name' => 'Second Term', 'sequence' => 2,
            'starts_on' => '2026-01-12', 'ends_on' => '2026-04-17',
        ]);
        $second->makeCurrent();

        CalendarEvent::create(['title' => 'First Term Sports Day', 'category' => 'sports', 'starts_on' => '2025-10-01', 'is_default' => false]);
        CalendarEvent::create(['title' => 'Second Term Debate', 'category' => 'event', 'starts_on' => '2026-02-10', 'is_default' => false]);
        CalendarEvent::create(['title' => 'A School Decided Day', 'category' => 'celebration', 'is_default' => false]);
        app(TenantManager::class)->forget();

        // Defaults to the current term (Second Term): its own event shows,
        // the other term's event does not, the undated one always shows.
        $this->actingAs($owner)->get(route('school.calendar.index', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('Second Term Debate')
            ->assertSee('A School Decided Day')
            ->assertDontSee('First Term Sports Day');

        // Switching to First Term flips which dated event is visible.
        $this->actingAs($owner)->get(route('school.calendar.index', ['school' => $school->slug, 'term_id' => $first->id]))
            ->assertOk()
            ->assertSee('First Term Sports Day')
            ->assertSee('A School Decided Day')
            ->assertDontSee('Second Term Debate');
    }
}
