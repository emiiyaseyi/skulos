<?php

namespace Tests\Feature;

use App\Mail\TrialRequestStatusMail;
use App\Models\School;
use App\Models\TrialRequest;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The public "Start a Trial" lead form and the platform admin review
 * workflow (user request 2026-07-09): brief form, admin approves or
 * rejects. Approving now provisions the school automatically (a later
 * request, 2026-07-09: "the school account should be setup
 * automatically") and emails the applicant their sign in details.
 */
class TrialRequestTest extends TestCase
{
    use RefreshDatabase;

    protected string $tenantDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->tenantDir = storage_path('framework/testing/tenants-'.getmypid());
        config(['tenancy.sqlite_path' => $this->tenantDir, 'tenancy.driver' => 'sqlite', 'app.domain' => 'localhost']);
        // Needed since 2026-07-16: approve() clones the "school-owner"
        // template role into the new school (fixed from assigning the
        // shared template row's id directly), which requires that
        // template to actually exist first.
        $this->seed(RbacSeeder::class);
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

    public function test_a_visitor_can_submit_a_trial_request(): void
    {
        $this->get('/trial')->assertOk();

        $this->post('/trial', [
            'school_name' => 'Sunrise Academy',
            'contact_name' => 'Bola Adeyemi',
            'email' => 'bola@sunrise.test',
            'phone' => '08012345678',
            'student_count' => 250,
            'message' => 'We have 3 campuses.',
        ])->assertRedirect(route('trial.thanks'));

        $this->assertDatabaseHas('trial_requests', [
            'school_name' => 'Sunrise Academy',
            'email' => 'bola@sunrise.test',
            'status' => TrialRequest::STATUS_PENDING,
        ]);
    }

    public function test_the_honeypot_field_silently_drops_bot_submissions(): void
    {
        $this->post('/trial', [
            'school_name' => 'Bot School',
            'contact_name' => 'Bot',
            'email' => 'bot@example.com',
            'website' => 'http://spam.example.com',
        ])->assertRedirect(route('trial.thanks'));

        $this->assertDatabaseMissing('trial_requests', ['school_name' => 'Bot School']);
    }

    public function test_repeated_submissions_are_rate_limited(): void
    {
        $payload = ['school_name' => 'A', 'contact_name' => 'B', 'email' => 'c@example.com'];

        for ($i = 0; $i < 3; $i++) {
            $this->post('/trial', $payload)->assertRedirect(route('trial.thanks'));
        }

        $this->post('/trial', $payload)->assertSessionHas('error');
        $this->assertSame(3, TrialRequest::count());
    }

    public function test_approving_a_trial_request_provisions_the_school_and_emails_sign_in_details(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        $trial = TrialRequest::create([
            'school_name' => 'Bright Kids', 'contact_name' => 'Ada', 'email' => 'ada@brightkids.test',
        ]);

        $this->actingAs($admin)->post(route('admin.trial-requests.approve', $trial), [
            'review_note' => 'We will set you up this week and schedule a demo.',
        ])->assertRedirect();

        $trial->refresh();
        $this->assertSame(TrialRequest::STATUS_APPROVED, $trial->status);
        $this->assertSame($admin->id, $trial->reviewed_by);
        $this->assertNotNull($trial->reviewed_at);
        $this->assertNotNull($trial->school_id);

        $school = School::find($trial->school_id);
        $this->assertNotNull($school);
        $this->assertSame('Bright Kids', $school->name);
        $this->assertTrue($school->isProvisioned());

        $newAdmin = User::where('email', 'ada@brightkids.test')->first();
        $this->assertNotNull($newAdmin);
        $this->assertTrue($newAdmin->isMemberOf($school));

        Mail::assertSent(TrialRequestStatusMail::class, function ($mail) use ($trial) {
            return $mail->hasTo($trial->email) && $mail->trial->is($trial) && ! empty($mail->credentials['password']);
        });
    }

    /**
     * Bug found 2026-07-16 (user report: a platform broadcast to "school
     * owners" reached 0 schools). The new owner's role_id used to point
     * at the shared TEMPLATE role (school_id null, id=1 for every
     * school), never cloned into this school first, so anything that
     * queries by a school-scoped role id (a platform broadcast's
     * recipient resolution, the school's own admins list) never matched
     * them at all. 5 of 7 real schools were found affected this way
     * before the fix, each missing exactly the one owner account created
     * through this exact code path.
     */
    public function test_the_new_owners_role_is_this_schools_own_cloned_role_not_the_shared_template(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        $trial = TrialRequest::create([
            'school_name' => 'Role Check Academy', 'contact_name' => 'Bola', 'email' => 'bola@rolecheck.test',
        ]);

        $this->actingAs($admin)->post(route('admin.trial-requests.approve', $trial));

        $trial->refresh();
        $school = School::find($trial->school_id);
        $newAdmin = User::where('email', 'bola@rolecheck.test')->first();

        $role = $newAdmin->roleIn($school);
        $this->assertNotNull($role);
        $this->assertSame('school-owner', $role->slug);
        $this->assertSame($school->id, $role->school_id, 'must be this school\'s own cloned role, not the shared template row (school_id null)');

        // Exactly the query PlatformBroadcastService::deliver() runs.
        $roleIds = \App\Models\Role::where('school_id', $school->id)->whereIn('slug', ['school-owner', 'school-admin'])->pluck('id');
        $members = $school->users()->wherePivotIn('role_id', $roleIds)->wherePivot('status', 'active')->get();
        $this->assertTrue($members->contains('id', $newAdmin->id), 'a platform broadcast to school owners must actually find this owner');
    }

    public function test_two_trials_with_the_same_school_name_get_distinct_slugs(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        $first = TrialRequest::create(['school_name' => 'Same Name', 'contact_name' => 'A', 'email' => 'a@example.com']);
        $second = TrialRequest::create(['school_name' => 'Same Name', 'contact_name' => 'B', 'email' => 'b@example.com']);

        $this->actingAs($admin)->post(route('admin.trial-requests.approve', $first));
        $this->actingAs($admin)->post(route('admin.trial-requests.approve', $second));

        $slugs = School::whereIn('id', [$first->fresh()->school_id, $second->fresh()->school_id])->pluck('slug');
        $this->assertCount(2, $slugs->unique());
    }

    public function test_platform_admin_can_reject_a_trial_request_and_the_applicant_is_emailed(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        $trial = TrialRequest::create([
            'school_name' => 'Far School', 'contact_name' => 'Tunde', 'email' => 'tunde@far.test',
        ]);

        $this->actingAs($admin)->post(route('admin.trial-requests.reject', $trial), [
            'review_note' => 'Outside our current coverage area.',
        ])->assertRedirect();

        $this->assertSame(TrialRequest::STATUS_REJECTED, $trial->fresh()->status);
        $this->assertNull($trial->fresh()->school_id);
        Mail::assertSent(TrialRequestStatusMail::class, fn ($mail) => $mail->hasTo('tunde@far.test'));
    }

    public function test_only_super_admin_can_review_trial_requests(): void
    {
        $regular = User::factory()->create();
        $trial = TrialRequest::create(['school_name' => 'X', 'contact_name' => 'Y', 'email' => 'z@example.com']);

        $this->actingAs($regular)->get(route('admin.trial-requests.index'))->assertNotFound();
        $this->actingAs($regular)->post(route('admin.trial-requests.approve', $trial))->assertNotFound();
    }

    public function test_admin_can_filter_trial_requests_by_status(): void
    {
        $admin = $this->superAdmin();
        TrialRequest::create(['school_name' => 'Pending One', 'contact_name' => 'A', 'email' => 'a@example.com']);
        TrialRequest::create(['school_name' => 'Approved One', 'contact_name' => 'B', 'email' => 'b@example.com', 'status' => TrialRequest::STATUS_APPROVED]);

        $this->actingAs($admin)->get(route('admin.trial-requests.index', ['status' => 'pending']))
            ->assertOk()->assertSee('Pending One')->assertDontSee('Approved One');

        $this->actingAs($admin)->get(route('admin.trial-requests.index', ['status' => 'approved']))
            ->assertOk()->assertSee('Approved One')->assertDontSee('Pending One');
    }

    /**
     * Audit follow-up 2026-07-30: append-only, never purged, so this grows
     * faster than the Schools table it feeds. Used to load with no bound.
     */
    public function test_the_trial_requests_list_paginates(): void
    {
        $admin = $this->superAdmin();
        for ($i = 0; $i < 35; $i++) {
            TrialRequest::create(['school_name' => "Bulk School {$i}", 'contact_name' => 'Contact', 'email' => "bulk{$i}@example.com"]);
        }

        $response = $this->actingAs($admin)->get(route('admin.trial-requests.index', ['status' => 'pending']));
        $response->assertOk();

        $trials = $response->viewData('trials');
        $this->assertSame(30, $trials->perPage());
        $this->assertSame(30, $trials->count());
        $this->assertSame(35, $trials->total());
        $this->assertSame(35, $response->viewData('counts')['pending'], 'the status tab counts must stay platform wide, not the page shown');
    }
}
