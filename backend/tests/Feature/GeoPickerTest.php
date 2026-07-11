<?php

namespace Tests\Feature;

use App\Models\Country;
use App\Models\Lga;
use App\Models\School;
use App\Models\State;
use App\Models\Tenant\Student;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\GeoSeeder;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * The reusable country to state to LGA picker (user request 2026-07-11)
 * and its wiring into the Student form: the /geo/* read only endpoints
 * behind it, and an end to end save through the actual form fields.
 */
class GeoPickerTest extends TestCase
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
        $this->seed(GeoSeeder::class);
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

    public function test_countries_endpoint_lists_nigeria_first_and_the_full_54(): void
    {
        $response = $this->get('http://localhost/geo/countries');
        $response->assertOk();

        $countries = $response->json();
        $this->assertCount(54, $countries);
        $this->assertSame('Nigeria', $countries[0]['name']);
        $this->assertSame('NG', $countries[0]['iso2']);

        // Everything after Nigeria is alphabetical.
        $rest = array_column(array_slice($countries, 1), 'name');
        $sorted = $rest;
        sort($sorted, SORT_STRING);
        $this->assertSame($sorted, $rest);
    }

    public function test_countries_endpoint_works_from_a_school_subdomain_too(): void
    {
        [$school] = $this->schoolWithMember('school-owner', 'geoone');

        $response = $this->get("http://{$school->slug}.localhost/geo/countries");
        $response->assertOk();
        $this->assertSame('Nigeria', $response->json()[0]['name']);
    }

    public function test_states_endpoint_returns_nigerias_37_states(): void
    {
        $nigeria = Country::where('iso2', 'NG')->firstOrFail();

        $response = $this->get("http://localhost/geo/countries/{$nigeria->id}/states");
        $response->assertOk();
        $this->assertCount(37, $response->json());
        $this->assertContains('Lagos', array_column($response->json(), 'name'));
    }

    public function test_lga_endpoint_returns_the_real_fetched_774_total_across_all_nigeria_states(): void
    {
        $nigeria = Country::where('iso2', 'NG')->firstOrFail();
        $stateIds = State::where('country_id', $nigeria->id)->pluck('id');

        $total = 0;
        foreach ($stateIds as $stateId) {
            $response = $this->get("http://localhost/geo/states/{$stateId}/lgas");
            $response->assertOk();
            $total += count($response->json());
        }

        $this->assertSame(Lga::count(), $total);
        $this->assertSame(774, $total);
    }

    public function test_lga_endpoint_is_empty_for_a_non_nigeria_state(): void
    {
        $ghana = Country::where('iso2', 'GH')->firstOrFail();
        $ghanaState = State::where('country_id', $ghana->id)->firstOrFail();

        $response = $this->get("http://localhost/geo/states/{$ghanaState->id}/lgas");
        $response->assertOk();
        $this->assertSame([], $response->json());
    }

    public function test_student_form_saves_country_state_and_lga_end_to_end(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'geotwo');

        $this->actingAs($admin)->post("http://{$school->slug}.localhost/students/create", [
            'admission_number' => 'GEO1',
            'first_name' => 'Ada',
            'last_name' => 'Obi',
            'country' => 'Nigeria',
            'state_of_origin' => 'Lagos',
            'lga' => 'Ikeja',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $student = Student::where('admission_number', 'GEO1')->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($student);
        $this->assertSame('Nigeria', $student->country);
        $this->assertSame('Lagos', $student->state_of_origin);
        $this->assertSame('Ikeja', $student->lga);
    }

    public function test_student_create_form_renders_the_picker_with_nigeria_first(): void
    {
        [$school, $admin] = $this->schoolWithMember('school-owner', 'geothree');

        $response = $this->actingAs($admin)->get("http://{$school->slug}.localhost/students/create");
        $response->assertOk();
        $response->assertSee('id="country"', false);
        $response->assertSee('<option value="Nigeria"', false);
    }
}
