<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * School Profile (2026-07-17, Settings nav reorganization): a school's
 * own institutional details, previously nowhere editable (Branding only
 * ever covered the visual side, Campuses only ever covered locations).
 * Extended 2026-07-22 (user request: "one should also be able to upload
 * the school logo there. Also one should be able to add addition
 * details there if needed") with a logo upload and registration number/
 * established year/alternate phone/website.
 */
class SchoolProfileTest extends TestCase
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

    public function test_an_owner_can_update_the_school_profile(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'profileone');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/settings/profile")->assertOk();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/profile", [
            'motto' => 'Excellence in all things',
            'email' => 'info@profileone.test',
            'phone' => '+2348000000000',
            'address' => '1 Test Street',
        ])->assertRedirect();

        $school->refresh();
        $this->assertSame('Excellence in all things', $school->motto);
        $this->assertSame('info@profileone.test', $school->email);
    }

    public function test_a_teacher_cannot_edit_the_school_profile(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'profiletwo');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/settings/profile")->assertForbidden();
    }

    /** User request 2026-07-22: "one should also be able to upload the school logo there." */
    public function test_owner_can_upload_a_logo_from_the_profile_screen(): void
    {
        Storage::fake('public');
        [$school, $owner] = $this->schoolWithMember('school-owner', 'profilethree');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/settings/profile")->assertOk()->assertSee('Logo');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/profile", [
            'logo' => UploadedFile::fake()->image('logo.png'),
        ])->assertRedirect();

        $school->refresh();
        $this->assertNotNull($school->logo_path);
        Storage::disk('public')->assertExists($school->logo_path);
    }

    /** User request 2026-07-22: "one should be able to add addition details there if needed." */
    public function test_owner_can_save_the_new_additional_detail_fields(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'profilefour');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/profile", [
            'registration_number' => 'RC-123456',
            'established_year' => 1998,
            'alternate_phone' => '08033334444',
            'website' => 'https://greenfield.example.com',
        ])->assertRedirect();

        $school->refresh();
        $this->assertSame('RC-123456', $school->registration_number);
        $this->assertSame(1998, $school->established_year);
        $this->assertSame('08033334444', $school->alternate_phone);
        $this->assertSame('https://greenfield.example.com', $school->website);

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/settings/profile")
            ->assertOk()->assertSee('RC-123456')->assertSee('1998')->assertSee('greenfield.example.com');
    }
}
