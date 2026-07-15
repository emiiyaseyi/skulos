<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Announcement;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Messaging increment 2 (2026-07-15): an optional "light image" on a
 * broadcast. Real image bytes go in, a WebP path comes out; only the path
 * is ever stored (standing "never store file bytes in the database" rule).
 */
class MessageImageAttachmentTest extends TestCase
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
    }

    protected function tearDown(): void
    {
        app(TenantManager::class)->forget();
        File::deleteDirectory($this->tenantDir);
        parent::tearDown();
    }

    public function test_an_uploaded_image_is_converted_to_webp_and_only_the_path_is_stored(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'imgone']));
        $owner = \App\Models\User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        // A real 800x600 JPEG (GD-backed fake, decodable, not a text stub).
        $image = UploadedFile::fake()->image('photo.jpg', 800, 600);

        $this->actingAs($owner)->post(route('school.messages.store', ['school' => $school->slug]), [
            'title' => 'With a photo', 'audience' => 'parents', 'scope' => 'all',
            'body' => '<p>See attached.</p>', 'image' => $image,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $broadcast = Announcement::broadcasts()->first();
        app(TenantManager::class)->forget();

        $this->assertNotNull($broadcast->image_path);
        $this->assertStringEndsWith('.webp', $broadcast->image_path, 'must be converted to WebP regardless of the upload format');
        Storage::disk('public')->assertExists($broadcast->image_path);
        $this->assertStringStartsWith("messages/{$school->slug}/", $broadcast->image_path);
    }

    public function test_the_stored_webp_is_downsized_from_an_oversized_original(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'imgtwo']));
        $owner = \App\Models\User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        // Wider than the 1600px cap; the stored copy must be capped.
        $image = UploadedFile::fake()->image('big.jpg', 4000, 3000);

        $this->actingAs($owner)->post(route('school.messages.store', ['school' => $school->slug]), [
            'title' => 'Big photo', 'audience' => 'parents', 'scope' => 'all',
            'body' => '<p>x</p>', 'image' => $image,
        ]);

        app(TenantManager::class)->use($school);
        $broadcast = Announcement::broadcasts()->first();
        app(TenantManager::class)->forget();

        $fullPath = Storage::disk('public')->path($broadcast->image_path);
        [$width, $height] = getimagesize($fullPath);
        $this->assertLessThanOrEqual(1600, max($width, $height));
    }

    public function test_a_broadcast_without_an_image_stores_no_path(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'imgthree']));
        $owner = \App\Models\User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        $this->actingAs($owner)->post(route('school.messages.store', ['school' => $school->slug]), [
            'title' => 'No photo', 'audience' => 'parents', 'scope' => 'all', 'body' => '<p>x</p>',
        ]);

        app(TenantManager::class)->use($school);
        $broadcast = Announcement::broadcasts()->first();
        app(TenantManager::class)->forget();

        $this->assertNull($broadcast->image_path);
        $this->assertNull($broadcast->imageUrl());
    }

    public function test_an_oversized_upload_is_rejected(): void
    {
        $school = app(TenantManager::class)->provision(School::factory()->create(['slug' => 'imgfour']));
        $owner = \App\Models\User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'school-owner');
        $school->users()->attach($owner->id, ['role_id' => $role->id, 'status' => 'active']);

        $tooBig = UploadedFile::fake()->create('huge.jpg', 6000, 'image/jpeg'); // 6MB, over the 5MB cap

        $this->actingAs($owner)->post(route('school.messages.store', ['school' => $school->slug]), [
            'title' => 'Too big', 'audience' => 'parents', 'scope' => 'all', 'body' => '<p>x</p>', 'image' => $tooBig,
        ])->assertSessionHasErrors('image');
    }
}
