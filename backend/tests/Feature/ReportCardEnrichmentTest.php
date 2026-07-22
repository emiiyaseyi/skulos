<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Arm;
use App\Models\Tenant\BehaviouralTrait;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentBehaviouralRating;
use App\Models\Tenant\StudentSubjectComment;
use App\Models\Tenant\StudentTermRemark;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\ReportCards\ReportCardPreferences;
use App\Services\ReportCards\ReportCardService;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Behavioural/psychomotor traits, physical/bio stats, per subject written
 * comments, and school/class stamp+signature (user request 2026-07-18,
 * built from 2 real report card templates supplied as reference: "there
 * should be a subsection under report card, where teachers can enter the
 * behavioural traits or psychomotor traits... where the school stamp and
 * signature should be placed").
 */
class ReportCardEnrichmentTest extends TestCase
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

    /** A class teacher user, linked to a Staff row that owns the given class arm, so TeacherScope resolves them to it. */
    protected function classTeacherFor(School $school, ClassArm $classArm): User
    {
        $user = User::factory()->create();
        $role = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        $school->users()->attach($user->id, ['role_id' => $role->id, 'status' => 'active']);

        app(TenantManager::class)->use($school);
        $staff = Staff::create([
            'staff_id' => 'STF/900', 'first_name' => 'Tola', 'last_name' => 'Bello', 'user_id' => $user->id,
        ]);
        $classArm->update(['class_teacher_staff_id' => $staff->id]);
        app(TenantManager::class)->forget();

        return $user;
    }

    public function test_admin_can_manage_the_behavioural_trait_catalog(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'trait-admin');

        // The catalog page seeds the default 8 traits from config on first view.
        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/traits")
            ->assertOk()->assertSee('Punctuality');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/traits", [
            'name' => 'Handling of Tools', 'type' => 'score', 'max_score' => 5,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $trait = BehaviouralTrait::where('name', 'Handling of Tools')->firstOrFail();
        $this->assertSame('score', $trait->type);
        $this->assertSame(5, $trait->max_score);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/traits/{$trait->id}/toggle-active")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertFalse($trait->fresh()->is_active);
        $otherTraitId = BehaviouralTrait::where('id', '!=', $trait->id)->first()->id;
        app(TenantManager::class)->forget();

        // Bulk reactivate covers the standing "bulk action on every list screen" rule.
        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/traits/bulk-action", [
            'trait_ids' => [$trait->id, $otherTraitId],
            'action' => 'activate',
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertTrue($trait->fresh()->is_active);
        app(TenantManager::class)->forget();
    }

    /** User request 2026-07-22: "one should be able to edit current behavioural/psychomotor traits that appears for each child reports." */
    public function test_admin_can_edit_an_existing_trait(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'trait-edit');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/traits")->assertSee('Edit');

        app(TenantManager::class)->use($school);
        $trait = BehaviouralTrait::where('name', 'Punctuality')->firstOrFail();
        $traitId = $trait->id;
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/traits/{$traitId}", [
            'name' => 'Timeliness', 'type' => 'score', 'max_score' => 10,
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $updated = BehaviouralTrait::findOrFail($traitId);
        $this->assertSame('Timeliness', $updated->name);
        $this->assertSame('score', $updated->type);
        $this->assertSame(10, $updated->max_score);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/traits")->assertSee('Timeliness');
    }

    public function test_a_teacher_without_review_permission_cannot_manage_traits(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'trait-noaccess');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/traits", [
            'name' => 'Extra', 'type' => 'remark',
        ])->assertForbidden();
    }

    /** User request 2026-07-22: "option to also upload the school wide stamp and signature should appear here too." */
    public function test_the_school_wide_stamp_and_signature_can_be_uploaded_from_report_settings(): void
    {
        Storage::fake('public');
        [$school, $owner] = $this->schoolWithMember('school-owner', 'rc-settings-stamp');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings")
            ->assertOk()->assertSee('Stamp and signature')->assertSee('Save default stamp');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/settings/branding", [
            'stamp' => UploadedFile::fake()->image('stamp.png'),
            'signature' => UploadedFile::fake()->image('signature.png'),
        ])->assertRedirect();

        $school->refresh();
        $this->assertNotNull($school->stamp_path);
        $this->assertNotNull($school->signature_path);
    }

    /** Uploading requires branding access, a stricter permission than the plain report_cards.view this settings page itself is gated on. */
    public function test_a_teacher_without_branding_access_cannot_see_the_school_wide_stamp_upload_form(): void
    {
        [$school, $teacher] = $this->schoolWithMember('class-teacher', 'rc-settings-stamp-noaccess');

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/report-cards/settings")
            ->assertOk()->assertDontSee('Save default stamp');
    }

    protected function setUpClassWithStudents(School $school): array
    {
        app(TenantManager::class)->use($school);
        $session = AcademicSession::create(['name' => '2025/2026']);
        $term = Term::create(['academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1]);
        $section = Section::create(['name' => 'Nursery', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'Pre Nursery', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $student = Student::create([
            'admission_number' => 'PN/1', 'first_name' => 'Zara', 'last_name' => 'Musa',
            'current_class_arm_id' => $classArm->id,
        ]);
        $subject = Subject::create(['name' => 'Numeracy']);
        app(\App\Services\Results\BehaviouralTraitCatalog::class)->ensureSeeded();
        $trait = BehaviouralTrait::first();
        app(TenantManager::class)->forget();

        return compact('term', 'class', 'classArm', 'student', 'subject', 'trait');
    }

    public function test_class_teacher_entry_saves_physical_stats_trait_ratings_and_remarks(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'entry-one');
        ['term' => $term, 'class' => $class, 'classArm' => $classArm, 'student' => $student, 'trait' => $trait] = $this->setUpClassWithStudents($school);

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry", [
            'term_id' => $term->id,
            'students' => [
                $student->id => [
                    'height' => '90cm', 'weight' => '13kg', 'club' => 'Music Club',
                    'traits' => [$trait->id => 'Excellent'],
                    'class_teacher_remark' => 'Settling in well.',
                    'principal_remark' => 'Keep it up.',
                ],
            ],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $remark = StudentTermRemark::where('student_id', $student->id)->where('term_id', $term->id)->firstOrFail();
        $this->assertSame('90cm', $remark->height);
        $this->assertSame('13kg', $remark->weight);
        $this->assertSame('Music Club', $remark->club);
        $this->assertSame('Settling in well.', $remark->class_teacher_remark);
        $this->assertSame('Keep it up.', $remark->principal_remark);

        $rating = StudentBehaviouralRating::where('student_id', $student->id)->where('behavioural_trait_id', $trait->id)->firstOrFail();
        $this->assertSame('Excellent', $rating->value);
        app(TenantManager::class)->forget();
    }

    /** class-teacher holds report_cards.update but not report_cards.release, so the principal_remark column must not be saved even if crafted into the request. */
    public function test_a_class_teacher_cannot_set_the_principal_remark(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'entry-two');
        ['term' => $term, 'class' => $class, 'classArm' => $classArm, 'student' => $student] = $this->setUpClassWithStudents($school);
        $teacher = $this->classTeacherFor($school, $classArm);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry", [
            'term_id' => $term->id,
            'students' => [
                $student->id => ['class_teacher_remark' => 'Fine.', 'principal_remark' => 'Should not be saved.'],
            ],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $remark = StudentTermRemark::where('student_id', $student->id)->where('term_id', $term->id)->first();
        $this->assertSame('Fine.', $remark->class_teacher_remark);
        $this->assertNull($remark->principal_remark);
        app(TenantManager::class)->forget();
    }

    public function test_a_class_teacher_outside_the_class_cannot_open_class_entry(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'entry-three');
        ['class' => $class, 'classArm' => $classArm] = $this->setUpClassWithStudents($school);

        // Give this teacher their own, unrelated class arm rather than the one under test.
        app(TenantManager::class)->use($school);
        $otherSection = Section::create(['name' => 'Primary', 'sequence' => 2]);
        $otherClass = SchoolClass::create(['section_id' => $otherSection->id, 'name' => 'Primary 1', 'sequence' => 1]);
        $otherArm = Arm::create(['name' => 'B']);
        $otherClassArm = ClassArm::create(['school_class_id' => $otherClass->id, 'arm_id' => $otherArm->id]);
        app(TenantManager::class)->forget();

        $teacher = $this->classTeacherFor($school, $otherClassArm);

        $this->actingAs($teacher)->get("http://{$school->slug}.localhost/report-cards/classes/{$class->id}/entry")
            ->assertNotFound();
    }

    public function test_report_card_service_includes_traits_physical_stats_and_subject_comments(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'svc-one');
        ['term' => $term, 'student' => $student, 'subject' => $subject, 'trait' => $trait] = $this->setUpClassWithStudents($school);

        app(TenantManager::class)->use($school);
        StudentTermRemark::create(['student_id' => $student->id, 'term_id' => $term->id, 'height' => '95cm', 'weight' => '14kg', 'club' => 'Art Club']);
        StudentBehaviouralRating::create(['student_id' => $student->id, 'term_id' => $term->id, 'behavioural_trait_id' => $trait->id, 'value' => 'Good']);
        StudentSubjectComment::create(['student_id' => $student->id, 'term_id' => $term->id, 'subject_id' => $subject->id, 'comment' => 'Counts confidently to 10.']);

        $data = app(ReportCardService::class)->forStudent($student, $term);
        app(TenantManager::class)->forget();

        $this->assertSame('95cm', $data['physical']['height']);
        $this->assertSame('Art Club', $data['physical']['club']);
        $this->assertSame('Counts confidently to 10.', $data['subject_comments']['Numeracy']);
        $ratedTrait = collect($data['traits'])->firstWhere('name', $trait->name);
        $this->assertSame('Good', $ratedTrait['value']);
        // Every active trait always appears, even ones this student was never rated on.
        $this->assertGreaterThan(1, count($data['traits']));
    }

    public function test_class_arm_stamp_overrides_the_school_default_but_falls_back_when_unset(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stamp-one');
        ['term' => $term, 'student' => $student, 'classArm' => $classArm] = $this->setUpClassWithStudents($school);

        app(TenantManager::class)->use($school);
        $school->update(['stamp_path' => 'schools/1/school-stamp.webp', 'signature_path' => 'schools/1/school-sig.webp']);
        $data = app(ReportCardService::class)->forStudent($student, $term);
        app(TenantManager::class)->forget();

        $this->assertStringContainsString('school-stamp.webp', $data['stamp_url']);

        app(TenantManager::class)->use($school);
        $classArm->update(['stamp_path' => 'schools/1/arms/9/arm-stamp.webp']);
        // A fresh Student instance, not the one reused above: forStudent()'s
        // first call already cached the currentClassArm relation on $student,
        // which would otherwise mask this update.
        $data = app(ReportCardService::class)->forStudent(Student::find($student->id), $term);
        app(TenantManager::class)->forget();

        $this->assertStringContainsString('arm-stamp.webp', $data['stamp_url']);
        $this->assertStringContainsString('school-sig.webp', $data['signature_url']); // signature was never overridden, still the school default
    }

    public function test_class_teacher_can_upload_their_own_stamp_and_signature_for_their_arm(): void
    {
        Storage::fake('public');
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stamp-two');
        ['classArm' => $classArm] = $this->setUpClassWithStudents($school);
        $teacher = $this->classTeacherFor($school, $classArm);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/arms/{$classArm->id}/stamp-signature", [
            'stamp' => UploadedFile::fake()->image('stamp.png'),
            'signature' => UploadedFile::fake()->image('signature.png'),
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $classArm->fresh();
        $this->assertNotNull($fresh->stamp_path);
        $this->assertNotNull($fresh->signature_path);
        $this->assertTrue(Storage::disk('public')->exists($fresh->stamp_path));
        app(TenantManager::class)->forget();
    }

    public function test_a_teacher_cannot_upload_stamp_signature_for_another_class_arm(): void
    {
        Storage::fake('public');
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stamp-three');
        ['classArm' => $classArm] = $this->setUpClassWithStudents($school);

        app(TenantManager::class)->use($school);
        $otherArm = ClassArm::create(['school_class_id' => $classArm->school_class_id, 'arm_id' => Arm::create(['name' => 'Z'])->id]);
        app(TenantManager::class)->forget();

        $teacher = $this->classTeacherFor($school, $classArm);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/arms/{$otherArm->id}/stamp-signature", [
            'stamp' => UploadedFile::fake()->image('stamp.png'),
        ])->assertNotFound();
    }

    public function test_resetting_all_class_stamp_signature_overrides_in_one_action(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stamp-reset');
        ['classArm' => $classArm] = $this->setUpClassWithStudents($school);

        app(TenantManager::class)->use($school);
        $classArm->update(['stamp_path' => 'schools/1/arms/9/arm-stamp.webp', 'signature_path' => 'schools/1/arms/9/arm-sig.webp']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/arms/reset-stamp-signature")
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $fresh = $classArm->fresh();
        $this->assertNull($fresh->stamp_path);
        $this->assertNull($fresh->signature_path);
        app(TenantManager::class)->forget();
    }

    /** A class teacher holds report_cards.update but not the stronger report_cards.assign_review this school wide reset needs. */
    public function test_a_class_teacher_cannot_reset_every_classs_stamp_and_signature(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'stamp-reset-noaccess');
        ['classArm' => $classArm] = $this->setUpClassWithStudents($school);
        $teacher = $this->classTeacherFor($school, $classArm);

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/arms/reset-stamp-signature")
            ->assertForbidden();
    }

    public function test_layout_designer_page_renders_with_the_default_blocks(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layout-one');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/settings/layout")
            ->assertOk()
            ->assertSee('School Logo')
            ->assertSee('Name of Learner');
    }

    public function test_admin_can_save_and_enable_a_custom_layout(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layout-two');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['school_name', 'logo'],
            'info' => ['student_name', 'class'],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $realLayout = app(\App\Services\ReportCards\ReportCardLayout::class);
        $this->assertTrue($realLayout->enabled());
        $this->assertSame(['school_name', 'logo'], $realLayout->zones()['header']);
        $this->assertSame(['student_name', 'class'], $realLayout->zones()['info']);
        app(TenantManager::class)->forget();
    }

    public function test_an_unknown_block_key_is_rejected(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layout-three');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['not_a_real_block'],
        ])->assertSessionHasErrors('header.0');
    }

    public function test_a_teacher_without_update_permission_cannot_save_the_layout(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'layout-noaccess');

        $this->actingAs($teacher)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1', 'header' => ['logo'],
        ])->assertForbidden();
    }

    public function test_disabled_custom_layout_leaves_the_default_template_header_unaffected(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layout-off');

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview/classic")
            ->assertOk()
            ->assertDontSee('class="rc-head rc-head-custom"', false);
    }

    public function test_enabled_custom_layout_renders_the_chosen_blocks_in_order(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'layout-on');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings/layout", [
            'enabled' => '1',
            'header' => ['school_name'],
            'info' => ['student_name', 'overall_position'],
        ])->assertRedirect();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/preview/classic")
            ->assertOk()
            ->assertSee('class="rc-head rc-head-custom"', false)
            ->assertSee('Ada Grace Obi') // student_name block, from sample()
            ->assertSee('Position'); // overall_position block label
    }

    public function test_evaluation_and_narrative_templates_render_the_new_fields_when_enabled(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'tmpl-eval');
        ['term' => $term, 'student' => $student, 'subject' => $subject, 'trait' => $trait] = $this->setUpClassWithStudents($school);

        app(TenantManager::class)->use($school);
        StudentTermRemark::create(['student_id' => $student->id, 'term_id' => $term->id, 'height' => '95cm', 'weight' => '14kg', 'club' => 'Art Club']);
        StudentBehaviouralRating::create(['student_id' => $student->id, 'term_id' => $term->id, 'behavioural_trait_id' => $trait->id, 'value' => 'Good']);
        StudentSubjectComment::create(['student_id' => $student->id, 'term_id' => $term->id, 'subject_id' => $subject->id, 'comment' => 'Counts confidently to 10.']);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings", [
            'template' => 'evaluation',
            'fields' => ['behavioural_traits' => '1', 'physical_stats' => '1'],
        ])->assertRedirect();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertSee($trait->name)
            ->assertSee('Good')
            ->assertSee('Art Club');

        $this->actingAs($owner)->post("http://{$school->slug}.localhost/report-cards/settings", [
            'template' => 'narrative',
            'fields' => [],
        ])->assertRedirect();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/report-cards/{$student->id}/{$term->id}")
            ->assertOk()
            ->assertSee('Numeracy')
            ->assertSee('Counts confidently to 10.')
            ->assertDontSee('Total Score');
    }
}
