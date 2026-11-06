<?php

namespace Tests\Feature;

use App\Mail\TrialRequestStatusMail;
use App\Models\Country;
use App\Models\School;
use App\Models\TrialRequest;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\RateLimiter;
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

    /**
     * Since 2026-11-06 (direct instruction: "instant access for setup
     * with manual review from the backend"), a valid submission no
     * longer waits on an admin -- it provisions a real, working school
     * immediately, free for a full year, and emails the sign in details
     * right away. The old "left pending for review" behaviour only
     * applies now when something stops instant provisioning (see the
     * duplicate-email and provisioning-failure tests below).
     */
    public function test_a_visitor_can_submit_a_trial_request_and_gets_instant_access(): void
    {
        Mail::fake();
        Country::create(['name' => 'Nigeria', 'iso2' => 'NG', 'sequence' => 1]);
        \App\Models\Plan::create([
            'name' => 'Foundation', 'slug' => 'foundation', 'price_per_student_per_term' => 500,
            'modules' => [], 'features' => [], 'is_purchasable' => true, 'is_active' => true, 'sort_order' => 1,
        ]);
        $this->seed(RbacSeeder::class);

        $this->get('/trial')->assertOk()->assertSee('Nigeria');

        $this->post('/trial', [
            'school_name' => 'Sunrise Academy',
            'country' => 'Nigeria',
            'preferred_slug' => 'sunriseacademy',
            'contact_name' => 'Bola Adeyemi',
            'email' => 'bola@sunrise.test',
            'phone' => '08012345678',
            'student_count' => 250,
            'message' => 'We have 3 campuses.',
        ])->assertRedirect(route('trial.thanks'));

        $trial = TrialRequest::where('email', 'bola@sunrise.test')->first();
        $this->assertNotNull($trial);
        $this->assertSame(TrialRequest::STATUS_APPROVED, $trial->status);
        $this->assertNull($trial->reviewed_by, 'auto-approved, not a human reviewer');
        $this->assertNotNull($trial->school_id);

        $school = School::find($trial->school_id);
        $this->assertNotNull($school);
        $this->assertTrue($school->isProvisioned());
        $this->assertSame('foundation', $school->plan->slug);
        $this->assertNotNull($school->next_billing_date);
        $this->assertSame(now()->addYear()->toDateString(), $school->next_billing_date->toDateString(), 'the free trial is a full year, concretely enforced');

        $admin = User::where('email', 'bola@sunrise.test')->first();
        $this->assertNotNull($admin);
        $this->assertTrue($admin->isMemberOf($school));

        Mail::assertSent(TrialRequestStatusMail::class, fn ($mail) => $mail->hasTo('bola@sunrise.test') && ! empty($mail->credentials['password']));
    }

    /**
     * An email that already has a real login anywhere on the platform
     * does not get a second school auto-created silently -- left pending
     * for a human to look at, so a returning customer never ends up with
     * a surprise duplicate account.
     */
    public function test_an_email_that_already_has_an_account_is_left_pending_not_auto_provisioned(): void
    {
        Mail::fake();
        Country::create(['name' => 'Nigeria', 'iso2' => 'NG', 'sequence' => 1]);
        User::factory()->create(['email' => 'existing@school.test']);

        $this->post('/trial', [
            'school_name' => 'Second School', 'country' => 'Nigeria', 'preferred_slug' => 'secondschool',
            'contact_name' => 'Existing Person', 'email' => 'existing@school.test',
        ])->assertRedirect(route('trial.thanks'));

        $trial = TrialRequest::where('email', 'existing@school.test')->first();
        $this->assertSame(TrialRequest::STATUS_PENDING, $trial->status);
        $this->assertNull($trial->school_id);
    }

    public function test_the_web_address_and_country_are_both_required(): void
    {
        Country::create(['name' => 'Nigeria', 'iso2' => 'NG', 'sequence' => 1]);

        $this->post('/trial', [
            'school_name' => 'No Address Or Country',
            'contact_name' => 'Bola Adeyemi',
            'email' => 'bola@sunrise.test',
        ])->assertSessionHasErrors(['preferred_slug', 'country']);

        $this->assertDatabaseMissing('trial_requests', ['school_name' => 'No Address Or Country']);
    }

    public function test_a_country_not_in_the_real_list_is_rejected(): void
    {
        Country::create(['name' => 'Nigeria', 'iso2' => 'NG', 'sequence' => 1]);

        $this->post('/trial', [
            'school_name' => 'Fake Country School',
            'country' => 'Not A Real Country',
            'preferred_slug' => 'fakecountryschool',
            'contact_name' => 'Bola Adeyemi',
            'email' => 'bola@sunrise.test',
        ])->assertSessionHasErrors(['country']);
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
        Mail::fake();
        Country::create(['name' => 'Nigeria', 'iso2' => 'NG', 'sequence' => 1]);
        $payload = [
            'school_name' => 'A', 'country' => 'Nigeria', 'preferred_slug' => 'rateschool',
            'contact_name' => 'B', 'email' => 'c@example.com',
        ];

        // Only the first of these 3 attempts the real instant-provisioning
        // path (the same email every time means attempts 2 and 3 hit the
        // "already has an account" guard and skip it, no Rbac/Plan setup
        // needed for them).
        $this->seed(RbacSeeder::class);
        \App\Models\Plan::create([
            'name' => 'Foundation', 'slug' => 'foundation', 'price_per_student_per_term' => 500,
            'modules' => [], 'features' => [], 'is_purchasable' => true, 'is_active' => true, 'sort_order' => 1,
        ]);

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
     * Real bug found 2026-08-07 live-verifying the whole trial flow end
     * to end: the approval email's own "Go to your school portal" button
     * ignored the school's real portal_url (shown correctly in the sign
     * in details box directly above it) and linked to the generic
     * platform marketing root instead — a brand new customer's very
     * first click after signup would have landed on the wrong page.
     */
    public function test_the_approval_emails_own_button_links_to_the_new_schools_real_portal_not_the_platform_root(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        $trial = TrialRequest::create([
            'school_name' => 'Button Check Academy', 'contact_name' => 'Chidi', 'email' => 'chidi@buttoncheck.test',
        ]);

        $this->actingAs($admin)->post(route('admin.trial-requests.approve', $trial));

        Mail::assertSent(TrialRequestStatusMail::class, function ($mail) {
            $html = $mail->render();

            return str_contains($html, 'href="'.$mail->credentials['portal_url'].'"')
                && ! str_contains($html, 'href="'.config('app.url').'"');
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

    /**
     * Since a trial now provisions itself instantly, "Approve" on a
     * request that is already a live school must not try to provision a
     * second one -- it is a no-op, not a crash or a duplicate school.
     */
    public function test_approving_an_already_provisioned_trial_is_a_no_op(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        $trial = TrialRequest::create([
            'school_name' => 'Already Live', 'contact_name' => 'A', 'email' => 'a@alreadylive.test',
            'status' => TrialRequest::STATUS_APPROVED, 'school_id' => School::factory()->create()->id,
        ]);

        $this->actingAs($admin)->post(route('admin.trial-requests.approve', $trial))->assertRedirect();

        $this->assertSame(1, School::count());
    }

    /**
     * "Reject" on a request that is already a live school (the normal
     * case now that submission provisions instantly) is an after-the-fact
     * intervention: it suspends that real school rather than pretending
     * no account was ever created.
     */
    public function test_rejecting_an_already_provisioned_trial_suspends_the_school(): void
    {
        Mail::fake();
        $admin = $this->superAdmin();
        $school = School::factory()->create(['status' => School::STATUS_ACTIVE]);
        $trial = TrialRequest::create([
            'school_name' => $school->name, 'contact_name' => 'A', 'email' => 'a@suspendme.test',
            'status' => TrialRequest::STATUS_APPROVED, 'school_id' => $school->id,
        ]);

        $this->actingAs($admin)->post(route('admin.trial-requests.reject', $trial))->assertRedirect();

        $this->assertSame(School::STATUS_SUSPENDED, $school->fresh()->status);
        $this->assertSame(TrialRequest::STATUS_REJECTED, $trial->fresh()->status);
    }

    /**
     * Security: the public thank-you page must read identically whether
     * this exact submission got instant access or was held back (an
     * already-registered email, or the global circuit breaker tripping).
     * A page that said different things for each would let anyone
     * enumerate which email addresses already have a SKULOS account by
     * submitting this unauthenticated form with a guessed address and
     * reading back which message came back.
     */
    public function test_the_thank_you_page_reads_the_same_whether_or_not_instant_access_was_granted(): void
    {
        Mail::fake();
        Country::create(['name' => 'Nigeria', 'iso2' => 'NG', 'sequence' => 1]);
        \App\Models\Plan::create([
            'name' => 'Foundation', 'slug' => 'foundation', 'price_per_student_per_term' => 500,
            'modules' => [], 'features' => [], 'is_purchasable' => true, 'is_active' => true, 'sort_order' => 1,
        ]);
        $this->seed(RbacSeeder::class);
        User::factory()->create(['email' => 'taken@school.test']);

        $instantResponse = $this->post('/trial', [
            'school_name' => 'Fresh School', 'country' => 'Nigeria', 'preferred_slug' => 'freshschool',
            'contact_name' => 'Fresh Person', 'email' => 'fresh@school.test',
        ])->assertRedirect(route('trial.thanks'));

        $heldBackResponse = $this->post('/trial', [
            'school_name' => 'Known School', 'country' => 'Nigeria', 'preferred_slug' => 'knownschool',
            'contact_name' => 'Known Person', 'email' => 'taken@school.test',
        ])->assertRedirect(route('trial.thanks'));

        $this->assertNotNull(TrialRequest::where('email', 'fresh@school.test')->first()->school_id, 'sanity check: this one really was instant');
        $this->assertNull(TrialRequest::where('email', 'taken@school.test')->first()->school_id, 'sanity check: this one really was held back');

        // Both requests redirect to the exact same URL with no query
        // string or flash key distinguishing the outcome -- and the page
        // itself carries no per-outcome branching to render differently.
        $this->assertSame($instantResponse->headers->get('location'), $heldBackResponse->headers->get('location'));
        $this->assertStringNotContainsString('?', $instantResponse->headers->get('location'));
    }

    /**
     * Security: instant provisioning does real I/O (a whole tenant
     * database) per submission, which the per IP rate limit alone does
     * not bound against an attacker spreading submissions across many
     * IPs. Past the platform wide cap, further submissions fall back to
     * the pending/manual-review path instead of provisioning.
     */
    public function test_a_platform_wide_cap_stops_instant_provisioning_once_tripped(): void
    {
        Mail::fake();
        Country::create(['name' => 'Nigeria', 'iso2' => 'NG', 'sequence' => 1]);
        \App\Models\Plan::create([
            'name' => 'Foundation', 'slug' => 'foundation', 'price_per_student_per_term' => 500,
            'modules' => [], 'features' => [], 'is_purchasable' => true, 'is_active' => true, 'sort_order' => 1,
        ]);
        $this->seed(RbacSeeder::class);
        RateLimiter::clear('trial-instant-provision|global');
        for ($i = 0; $i < 20; $i++) {
            RateLimiter::hit('trial-instant-provision|global', 3600);
        }

        $this->post('/trial', [
            'school_name' => 'Overflow School', 'country' => 'Nigeria', 'preferred_slug' => 'overflowschool',
            'contact_name' => 'Overflow Person', 'email' => 'overflow@school.test',
        ])->assertRedirect(route('trial.thanks'));

        $trial = TrialRequest::where('email', 'overflow@school.test')->first();
        $this->assertSame(TrialRequest::STATUS_PENDING, $trial->status);
        $this->assertNull($trial->school_id);
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
