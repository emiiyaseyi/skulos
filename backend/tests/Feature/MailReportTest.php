<?php

namespace Tests\Feature;

use App\Models\MailLog;
use App\Models\School;
use App\Models\User;
use App\Services\Tenancy\TenantManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The platform mail report (2026-08-19, direct instruction): a total
 * sent so far, a per-school breakdown by audience, filterable by date
 * range, and a per-school drill-down reachable by clicking its name.
 */
class MailReportTest extends TestCase
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

    protected function superAdmin(): User
    {
        $u = User::factory()->create();
        $u->forceFill(['is_super_admin' => true])->save();

        return $u;
    }

    public function test_the_report_shows_the_real_platform_wide_total(): void
    {
        MailLog::create(['recipient' => 'a@example.com', 'status' => MailLog::STATUS_SENT]);
        MailLog::create(['recipient' => 'b@example.com', 'status' => MailLog::STATUS_SENT]);

        $this->actingAs($this->superAdmin())->get(route('admin.mail-report.index'))
            ->assertOk()->assertSee('2');
    }

    public function test_each_school_shows_its_own_totals_broken_down_by_audience(): void
    {
        $school = School::factory()->create(['name' => 'Report Academy']);
        MailLog::create(['recipient' => 'p@example.com', 'status' => MailLog::STATUS_SENT, 'school_id' => $school->id, 'audience' => MailLog::AUDIENCE_PARENT]);
        MailLog::create(['recipient' => 's@example.com', 'status' => MailLog::STATUS_SENT, 'school_id' => $school->id, 'audience' => MailLog::AUDIENCE_STUDENT]);
        MailLog::create(['recipient' => 'x@example.com', 'status' => MailLog::STATUS_SENT, 'school_id' => $school->id, 'audience' => MailLog::AUDIENCE_STUDENT]);

        $response = $this->actingAs($this->superAdmin())->get(route('admin.mail-report.index'))->assertOk();
        $response->assertSee('Report Academy');

        $row = $response->viewData('schools')->firstWhere('name', 'Report Academy');
        // 3 total, 1 parent, 2 students -- proves the breakdown is real
        // per-audience counting, not just a repeated total.
        $this->assertSame(3, $row->mail_total);
        $this->assertSame(1, $row->mail_parent);
        $this->assertSame(2, $row->mail_student);
        $this->assertSame(0, $row->mail_staff);
    }

    public function test_clicking_a_school_name_reaches_its_own_detail_report(): void
    {
        $school = School::factory()->create(['name' => 'Drilldown School']);
        MailLog::create(['recipient' => 'a@example.com', 'status' => MailLog::STATUS_SENT, 'school_id' => $school->id, 'audience' => MailLog::AUDIENCE_APPLICANT]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.mail-report.index'))
            ->assertOk()
            ->assertSee(route('admin.mail-report.show', $school), false);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.mail-report.show', $school))
            ->assertOk()
            ->assertSee('Drilldown School');
    }

    public function test_a_date_range_filter_excludes_mail_outside_it(): void
    {
        $school = School::factory()->create(['name' => 'Range School']);
        $old = MailLog::create(['recipient' => 'old@example.com', 'status' => MailLog::STATUS_SENT, 'school_id' => $school->id]);
        $old->forceFill(['created_at' => now()->subYears(2)])->save();
        MailLog::create(['recipient' => 'new@example.com', 'status' => MailLog::STATUS_SENT, 'school_id' => $school->id]);

        // Only the one sent this year counted, the 2-year-old one excluded.
        $this->actingAs($this->superAdmin())
            ->get(route('admin.mail-report.show', $school).'?range=this_year')
            ->assertOk()
            ->assertViewHas('total', 1);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.mail-report.show', $school).'?range=all')
            ->assertOk()
            ->assertViewHas('total', 2);
    }

    public function test_the_since_created_filter_scopes_to_a_real_school_only(): void
    {
        $school = School::factory()->create(['name' => 'Creation School']);
        MailLog::create(['recipient' => 'a@example.com', 'status' => MailLog::STATUS_SENT, 'school_id' => $school->id]);

        $this->actingAs($this->superAdmin())
            ->get(route('admin.mail-report.show', $school).'?range=created')
            ->assertOk()
            ->assertSee($school->created_at->toFormattedDateString());
    }

    public function test_a_non_super_admin_cannot_reach_the_report(): void
    {
        $this->actingAs(User::factory()->create())
            ->get(route('admin.mail-report.index'))
            ->assertNotFound();
    }
}
