<?php

namespace Tests\Feature;

use App\Models\School;
use App\Models\Tenant\Arm;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Section;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TimetableEntry;
use App\Models\Tenant\TimetablePeriod;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

/**
 * Timetable Management (Advance plan feature, user request 2026-07-18:
 * deferred, then reversed the same session and built). Only an admin
 * tier role (timetable.update via the blanket timetable.* grant) can
 * build/edit a class's grid; every scoped role (timetable.view only)
 * only ever sees its own schedule via "My Timetable".
 */
class TimetableTest extends TestCase
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

    /** Two class arms, two subjects, two staff, one period. Returns [classArmA, classArmB, subject, staffA, staffB, period]. */
    protected function seedAcademics(School $school): array
    {
        app(TenantManager::class)->use($school);

        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $classA = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $classB = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 2', 'sequence' => 2]);
        $arm = Arm::create(['name' => 'A']);
        $classArmA = ClassArm::create(['school_class_id' => $classA->id, 'arm_id' => $arm->id]);
        $classArmB = ClassArm::create(['school_class_id' => $classB->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $classA->subjects()->attach($subject->id);
        $classB->subjects()->attach($subject->id);
        $staffA = Staff::create(['staff_id' => 'STF1', 'first_name' => 'Ada', 'last_name' => 'Teacher']);
        $staffB = Staff::create(['staff_id' => 'STF2', 'first_name' => 'Femi', 'last_name' => 'Teacher']);
        $period = TimetablePeriod::create(['name' => 'Period 1', 'sequence' => 1, 'starts_at' => '08:00', 'ends_at' => '08:40', 'is_teaching_period' => true]);

        app(TenantManager::class)->forget();

        return [$classArmA, $classArmB, $subject, $staffA, $staffB, $period];
    }

    /** Two real terms, both created inside the currently connected tenant. Returns [termOne, termTwo]. */
    protected function twoTerms(): array
    {
        $session = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026']);
        $termOne = \App\Models\Tenant\Term::create([
            'academic_session_id' => $session->id, 'name' => 'First Term', 'sequence' => 1, 'is_current' => true,
            'starts_on' => now()->subDays(10), 'ends_on' => now()->addDays(50),
        ]);
        $termTwo = \App\Models\Tenant\Term::create([
            'academic_session_id' => $session->id, 'name' => 'Second Term', 'sequence' => 2, 'is_current' => false,
            'starts_on' => now()->addDays(60), 'ends_on' => now()->addDays(110),
        ]);

        return [$termOne, $termTwo];
    }

    public function test_an_admin_can_apply_default_periods(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttone');

        $this->actingAs($owner)->post(route('school.timetable.periods.apply-defaults', ['school' => $school->slug]))
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertEquals(10, TimetablePeriod::count());
        $this->assertTrue(TimetablePeriod::where('name', 'Break')->value('is_teaching_period') === false);
        app(TenantManager::class)->forget();
    }

    /** User report 2026-07-28 audit gap: a school's Nursery/Primary periods often run a different length than Secondary's. */
    public function test_a_section_previews_the_shared_catalog_until_it_has_periods_of_its_own(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttsecprev');
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        TimetablePeriod::create(['name' => 'Period 1', 'sequence' => 1, 'starts_at' => '08:00', 'ends_at' => '08:40', 'is_teaching_period' => true]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($owner)->get(route('school.timetable.periods', ['school' => $school->slug, 'section_id' => $section->id]));

        $response->assertOk()
            ->assertSee('Following the shared default catalog')
            ->assertSee('Period 1')
            ->assertDontSee('Remove selected');
    }

    public function test_forking_a_section_gives_it_its_own_independent_period_catalog(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttsecfork');
        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Primary', 'sequence' => 1]);
        TimetablePeriod::create(['name' => 'Period 1', 'sequence' => 1, 'starts_at' => '08:00', 'ends_at' => '08:40', 'is_teaching_period' => true]);
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.timetable.periods.fork-section', ['school' => $school->slug]), ['section_id' => $section->id])
            ->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertSame(1, TimetablePeriod::where('section_id', $section->id)->count());
        $forked = TimetablePeriod::where('section_id', $section->id)->first();
        // Retiming the section's own forked copy must never touch the shared row it was copied from.
        $forked->update(['starts_at' => '08:00', 'ends_at' => '08:35']);
        $sharedStillIntact = TimetablePeriod::whereNull('section_id')->first();
        $this->assertSame('08:40', substr((string) $sharedStillIntact->ends_at, 0, 5));
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get(route('school.timetable.periods', ['school' => $school->slug, 'section_id' => $section->id]))
            ->assertOk()->assertDontSee('Following the shared default catalog');
    }

    /** The real end-to-end use case: a class arm in a section with its own, shorter periods uses THOSE times, not the shared catalog. */
    public function test_a_class_arms_grid_uses_its_own_sections_periods_once_forked(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttsecgrid');
        app(TenantManager::class)->use($school);
        $primary = Section::create(['name' => 'Primary', 'sequence' => 1]);
        $secondary = Section::create(['name' => 'Secondary', 'sequence' => 2]);
        $primaryClass = SchoolClass::create(['section_id' => $primary->id, 'name' => 'Basic 1', 'sequence' => 1]);
        $secondaryClass = SchoolClass::create(['section_id' => $secondary->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $primaryArm = ClassArm::create(['school_class_id' => $primaryClass->id, 'arm_id' => $arm->id]);
        $secondaryArm = ClassArm::create(['school_class_id' => $secondaryClass->id, 'arm_id' => $arm->id]);
        TimetablePeriod::create(['name' => 'Period 1', 'sequence' => 1, 'starts_at' => '08:00', 'ends_at' => '08:40', 'is_teaching_period' => true]);
        // Primary's own, shorter period, same name as the shared one but a different time.
        TimetablePeriod::create(['section_id' => $primary->id, 'name' => 'Period 1', 'sequence' => 1, 'starts_at' => '08:00', 'ends_at' => '08:30', 'is_teaching_period' => true]);
        app(TenantManager::class)->forget();

        $primaryResponse = $this->actingAs($owner)->get(route('school.timetable.index', ['school' => $school->slug, 'class_arm_id' => $primaryArm->id]));
        $secondaryResponse = $this->actingAs($owner)->get(route('school.timetable.index', ['school' => $school->slug, 'class_arm_id' => $secondaryArm->id]));

        $primaryResponse->assertOk()->assertSee('08:00 to 08:30', false);
        $secondaryResponse->assertOk()->assertSee('08:00 to 08:40', false);
    }

    public function test_an_admin_can_build_a_class_weekly_grid(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'tttwo');
        [$classArmA, , $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ])->assertRedirect();

        $this->actingAs($owner)->get(route('school.timetable.index', ['school' => $school->slug, 'class_arm_id' => $classArmA->id]))
            ->assertOk()
            ->assertSee('Mathematics')
            ->assertSee('Ada Teacher');
    }

    /** User request 2026-07-21: "there should be a timetable like this showing for all classes in the school... at a glance". */
    public function test_the_master_timetable_shows_every_classs_grid_on_one_screen(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttmaster');
        [$classArmA, $classArmB, $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        $response = $this->actingAs($owner)->get(route('school.timetable.master', ['school' => $school->slug]));

        $response->assertOk()
            ->assertSee('JSS 1 A')
            ->assertSee('JSS 2 A')
            ->assertSee('Mathematics')
            ->assertSee('Ada Teacher')
            ->assertSee('Free'); // classArmB's Monday period 1 has nothing booked
    }

    /**
     * User request 2026-07-28: "Timetable pages should also have term
     * switcher too." A term-specific save must never touch the
     * universal (term_id NULL) rows every already-provisioned school's
     * timetable already is, and a cell the selected term never
     * overrode must still fall back to the universal one.
     */
    public function test_saving_for_a_specific_term_does_not_touch_the_universal_grid(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'tttermone');
        [$classArmA, , $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        $secondSubject = Subject::create(['name' => 'English Language']);
        $classArmA->schoolClass->subjects()->attach($secondSubject->id);
        [$termOne] = $this->twoTerms();
        app(TenantManager::class)->forget();

        // The universal grid: Mathematics on Monday. term_id=all is the
        // explicit "All terms" sentinel: absent now defaults to the
        // school's current term (user directive 2026-07-28), not
        // universal, so this can no longer rely on omitting it.
        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id, 'term_id' => 'all']), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ])->assertRedirect();

        // Term One's own override: English Language instead, same slot.
        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id, 'term_id' => $termOne->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $secondSubject->id, 'staff_id' => $staffA->id]]],
        ])->assertRedirect();

        // "All terms" still shows the untouched universal grid.
        $this->actingAs($owner)->get(route('school.timetable.index', ['school' => $school->slug, 'class_arm_id' => $classArmA->id, 'term_id' => 'all']))
            ->assertOk()->assertSee('Mathematics');

        // Term One shows its own override for the same slot.
        $this->actingAs($owner)->get(route('school.timetable.index', ['school' => $school->slug, 'class_arm_id' => $classArmA->id, 'term_id' => $termOne->id]))
            ->assertOk()->assertSee('English Language');

        app(TenantManager::class)->use($school);
        $this->assertSame(2, TimetableEntry::count());
        $this->assertNull(TimetableEntry::where('subject_id', $subject->id)->value('term_id'));
        $this->assertSame($termOne->id, TimetableEntry::where('subject_id', $secondSubject->id)->value('term_id'));
        app(TenantManager::class)->forget();
    }

    /** A cell a term never overrode still falls back to the universal row, for the same class arm, on the same request. */
    public function test_a_terms_grid_falls_back_to_the_universal_row_for_an_unoverridden_cell(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'tttermfallback');
        [$classArmA, , $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        [$termOne] = $this->twoTerms();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id, 'term_id' => 'all']), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        // Term One never saved anything of its own, so it still shows the universal cell.
        $this->actingAs($owner)->get(route('school.timetable.index', ['school' => $school->slug, 'class_arm_id' => $classArmA->id, 'term_id' => $termOne->id]))
            ->assertOk()->assertSee('Mathematics');
    }

    /**
     * Real bug found live 2026-08-05, greenfield: after a full week of
     * real entries was populated, the Master Timetable showed every
     * class 100% "Free" the moment a real term was selected, even
     * though the universal grid was fully saved. Root cause:
     * Eloquent\Collection::merge() (unlike the base Collection's) dedupes
     * by the MODEL'S OWN primary key and reindexes numerically,
     * silently discarding gridForClassArm()'s day:period string keys.
     * The earlier fallback test above only ever saved ONE cell and read
     * it back through the editable builder view, whose subject <select>
     * lists every subject regardless of grid data, so assertSee passed
     * even with a fully broken merge. This test uses the read-only
     * Master Timetable (no such select) across TWO periods with a real
     * term selected, the shape that actually exposed the bug live.
     */
    public function test_a_terms_master_timetable_grid_shows_every_universal_cell_not_free(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttmastertermmerge');
        [$classArmA, , $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $periodOne = TimetablePeriod::first();
        $periodTwo = TimetablePeriod::create(['name' => 'Period 2', 'sequence' => 2, 'starts_at' => '08:40', 'ends_at' => '09:20', 'is_teaching_period' => true]);
        [$termOne] = $this->twoTerms();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id, 'term_id' => 'all']), [
            'cells' => [
                'monday' => [
                    $periodOne->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id],
                    $periodTwo->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id],
                ],
            ],
        ])->assertRedirect();

        $this->actingAs($owner)->get(route('school.timetable.master', ['school' => $school->slug, 'term_id' => $termOne->id]))
            ->assertOk()->assertSee('Ada Teacher');

        // Assert at the service level, exactly where the bug actually
        // lived, rather than scraping HTML: with the merge bug present,
        // BOTH keys below silently disappear (the whole grid reindexes
        // to 0,1,2...) even though class arm A's own Monday cells were
        // saved and never overridden by term one.
        app(TenantManager::class)->use($school);
        $grid = (new \App\Services\Timetable\TimetableService())->gridForClassArm($classArmA->id, $termOne);
        app(TenantManager::class)->forget();
        $this->assertArrayHasKey('monday:'.$periodOne->id, $grid);
        $this->assertArrayHasKey('monday:'.$periodTwo->id, $grid);
        $this->assertSame($subject->id, $grid['monday:'.$periodOne->id]->subject_id);
        $this->assertSame($subject->id, $grid['monday:'.$periodTwo->id]->subject_id);
    }

    /** A teacher genuinely double booked within the same term (or the universal grid) is skipped, matching the pre-term-switcher behaviour. */
    public function test_a_teacher_conflict_is_still_caught_within_the_same_term(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'tttermconflict');
        [$classArmA, $classArmB, $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        [$termOne] = $this->twoTerms();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id, 'term_id' => $termOne->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        // Same teacher, same term, same day/period, a different class: skipped.
        $response = $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmB->id, 'term_id' => $termOne->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        $response->assertRedirect();
        app(TenantManager::class)->use($school);
        $this->assertNull(TimetableEntry::where('class_arm_id', $classArmB->id)->first());
        app(TenantManager::class)->forget();
    }

    /** The identical teacher/day/period booked in a DIFFERENT term is not a conflict, a school can restructure who teaches what between terms. */
    public function test_a_teacher_booked_in_a_different_term_is_not_a_conflict(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'tttermnoconflict');
        [$classArmA, $classArmB, $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        [$termOne, $termTwo] = $this->twoTerms();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id, 'term_id' => $termOne->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        $response = $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmB->id, 'term_id' => $termTwo->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        $response->assertRedirect();
        app(TenantManager::class)->use($school);
        $this->assertNotNull(TimetableEntry::where('class_arm_id', $classArmB->id)->where('term_id', $termTwo->id)->first());
        app(TenantManager::class)->forget();
    }

    /** User directive 2026-07-28: "the timetable term switcher should have the school current term as the default one." */
    public function test_a_first_visit_with_no_term_selected_defaults_to_the_school_current_term(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'tttermdefault');
        [$classArmA, , $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        [$termOne] = $this->twoTerms();
        app(TenantManager::class)->forget();

        // Saved for Term One (the current term) specifically.
        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id, 'term_id' => $termOne->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        // No term_id in the URL at all: shows Term One's grid by
        // default, not the (empty) universal grid.
        $this->actingAs($owner)->get(route('school.timetable.index', ['school' => $school->slug, 'class_arm_id' => $classArmA->id]))
            ->assertOk()
            ->assertSee('Mathematics');
    }

    /**
     * User report 2026-07-28: "why are all the term not organized
     * serially, it should start with the most recent year." Real bug
     * found live: an AcademicSession created out of chronological
     * order (a historical session added AFTER a newer one already
     * existed) gets a higher row id despite being an earlier year, so
     * sorting terms by their session's own id put years out of order.
     */
    public function test_terms_sort_by_real_chronological_year_not_session_creation_order(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'tttermchrono');
        app(TenantManager::class)->use($school);
        // Deliberately created out of chronological order: the older
        // 2024/2025 session is inserted SECOND, getting a higher id
        // than the newer 2025/2026 session inserted first.
        $recent = \App\Models\Tenant\AcademicSession::create(['name' => '2025/2026 Session']);
        $older = \App\Models\Tenant\AcademicSession::create(['name' => '2024/2025 Session']);
        $olderTerm = \App\Models\Tenant\Term::create(['academic_session_id' => $older->id, 'name' => 'First Term', 'sequence' => 1]);
        $recentTerm = \App\Models\Tenant\Term::create(['academic_session_id' => $recent->id, 'name' => 'First Term', 'sequence' => 1]);
        $terms = \App\Models\Tenant\Term::with('session')->get()->sortByDesc(fn ($t) => $t->chronologicalSortKey())->values();
        app(TenantManager::class)->forget();

        $this->assertSame($recentTerm->id, $terms->first()->id);
        $this->assertSame($olderTerm->id, $terms->last()->id);
    }

    public function test_a_scoped_teacher_cannot_reach_the_master_timetable(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'ttmastertwo');

        $this->actingAs($teacher)->get(route('school.timetable.master', ['school' => $school->slug]))->assertForbidden();
    }

    /** User request 2026-07-22: "Make the Master Timetable exportable as csv or pdf." */
    public function test_the_master_timetable_can_be_exported_as_csv(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttmasterexport');
        [$classArmA, , $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        $response = $this->actingAs($owner)->get(route('school.timetable.master.export', ['school' => $school->slug]));

        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Day,Class,Period,Time,Subject,Teacher', $csv);
        $this->assertStringContainsString('Mathematics', $csv);
        $this->assertStringContainsString('Ada Teacher', $csv);
    }

    public function test_a_teacher_double_booked_at_the_same_slot_is_skipped_not_double_booked(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttthree');
        [$classArmA, $classArmB, $subject, $staffA] = $this->seedAcademics($school);
        app(TenantManager::class)->use($school);
        $period = TimetablePeriod::first();
        app(TenantManager::class)->forget();

        // Book staffA into class A, Monday period 1.
        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmA->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ]);

        // Try to also book staffA into class B, same Monday period 1: conflict, skipped.
        $this->actingAs($owner)->post(route('school.timetable.save', ['school' => $school->slug, 'classArmId' => $classArmB->id]), [
            'cells' => ['monday' => [$period->id => ['subject_id' => $subject->id, 'staff_id' => $staffA->id]]],
        ])->assertRedirect();

        app(TenantManager::class)->use($school);
        $this->assertEquals(1, TimetableEntry::where('staff_id', $staffA->id)->count());
        app(TenantManager::class)->forget();
    }

    public function test_a_scoped_teacher_cannot_reach_the_admin_grid_editor(): void
    {
        [$school, $teacher] = $this->schoolWithMember('subject-teacher', 'ttfour');

        $this->actingAs($teacher)->get(route('school.timetable.index', ['school' => $school->slug]))->assertForbidden();
    }

    public function test_a_teacher_sees_their_own_schedule_on_my_timetable(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('subject-teacher', 'ttfive');
        [$classArmA, , $subject, $staffA, , $period] = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $staffA->update(['user_id' => $teacherUser->id]);
        TimetableEntry::create(['class_arm_id' => $classArmA->id, 'timetable_period_id' => $period->id, 'day_of_week' => 'monday', 'subject_id' => $subject->id, 'staff_id' => $staffA->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get(route('school.timetable.mine', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('Mathematics')
            ->assertSee('own teaching schedule');
    }

    /**
     * User report 2026-07-25: timetable export was only reachable from
     * the admin only Master Timetable, so a teacher, parent, or student
     * viewing their own "My Timetable" had no way to export it at all.
     */
    public function test_a_teacher_can_export_their_own_timetable_as_csv(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('subject-teacher', 'ttexportmine');
        [$classArmA, , $subject, $staffA, , $period] = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $staffA->update(['user_id' => $teacherUser->id]);
        TimetableEntry::create(['class_arm_id' => $classArmA->id, 'timetable_period_id' => $period->id, 'day_of_week' => 'monday', 'subject_id' => $subject->id, 'staff_id' => $staffA->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get(route('school.timetable.mine', ['school' => $school->slug]))
            ->assertOk()->assertSee('Export CSV');

        $response = $this->actingAs($teacherUser)->get(route('school.timetable.mine.export', ['school' => $school->slug]));
        $response->assertOk();
        $csv = $response->streamedContent();
        $this->assertStringContainsString('Day,Period,Time,Subject,Teacher', $csv);
        $this->assertStringContainsString('Mathematics', $csv);
    }

    public function test_a_student_sees_their_class_schedule_on_my_timetable(): void
    {
        [$school, $studentUser] = $this->schoolWithMember('student', 'ttsix');
        [$classArmA, , $subject, $staffA, , $period] = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        TimetableEntry::create(['class_arm_id' => $classArmA->id, 'timetable_period_id' => $period->id, 'day_of_week' => 'monday', 'subject_id' => $subject->id, 'staff_id' => $staffA->id]);
        $student = Student::create(['admission_number' => 'STU1', 'first_name' => 'Chi', 'last_name' => 'Student', 'current_class_arm_id' => $classArmA->id, 'user_id' => $studentUser->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($studentUser)->get(route('school.timetable.mine', ['school' => $school->slug]))
            ->assertOk()
            ->assertSee('Mathematics');
    }

    /**
     * User request 2026-07-22: "when the teacher clicks the timetable
     * menu, he should be able to select class timetable or the My
     * timetable option." A class teacher gets a toggle, defaulting to
     * their own class's full grid.
     */
    public function test_a_class_teacher_can_toggle_between_class_and_personal_timetable(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('class-teacher', 'ttclasstoggle');
        [$classArmA, $classArmB, $subject, $staffA, $staffB, $period] = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $staffA->update(['user_id' => $teacherUser->id]);
        $classArmA->update(['class_teacher_staff_id' => $staffA->id]);
        // One entry on the class teacher's own class taught by someone else, and one on a different class the teacher personally teaches, so class view and personal view are provably different.
        TimetableEntry::create(['class_arm_id' => $classArmA->id, 'timetable_period_id' => $period->id, 'day_of_week' => 'monday', 'subject_id' => $subject->id, 'staff_id' => $staffB->id]);
        TimetableEntry::create(['class_arm_id' => $classArmB->id, 'timetable_period_id' => $period->id, 'day_of_week' => 'tuesday', 'subject_id' => $subject->id, 'staff_id' => $staffA->id]);
        app(TenantManager::class)->forget();

        // Default (no ?view=) shows the class teacher's own class, including the OTHER teacher's Monday slot.
        $default = $this->actingAs($teacherUser)->get(route('school.timetable.mine', ['school' => $school->slug]));
        $default->assertOk()->assertSee('Class Timetable')->assertSee('My Timetable')->assertSee('Femi Teacher');

        // ?view=personal switches to their own cross-class teaching schedule instead.
        $this->actingAs($teacherUser)->get(route('school.timetable.mine', ['school' => $school->slug, 'view' => 'personal']))
            ->assertOk()->assertSee('JSS 2'); // taught there Tuesday, not on their own class
    }

    /** A subject teacher (never a class teacher) must never see the toggle at all, straight to their own schedule. */
    public function test_a_subject_teacher_never_sees_the_class_timetable_toggle(): void
    {
        [$school, $teacherUser] = $this->schoolWithMember('subject-teacher', 'ttnotoggle');
        [$classArmA, , $subject, $staffA, , $period] = $this->seedAcademics($school);

        app(TenantManager::class)->use($school);
        $staffA->update(['user_id' => $teacherUser->id]);
        TimetableEntry::create(['class_arm_id' => $classArmA->id, 'timetable_period_id' => $period->id, 'day_of_week' => 'monday', 'subject_id' => $subject->id, 'staff_id' => $staffA->id]);
        app(TenantManager::class)->forget();

        $this->actingAs($teacherUser)->get(route('school.timetable.mine', ['school' => $school->slug]))
            ->assertOk()->assertDontSee('Class Timetable');
    }

    public function test_the_nav_shows_timetable_for_an_admin_and_my_timetable_for_a_scoped_role(): void
    {
        [$school, $owner] = $this->schoolWithMember('school-owner', 'ttseven');
        app(TenantManager::class)->use($school);
        $plan = \App\Models\Plan::first();
        app(TenantManager::class)->forget();

        $this->actingAs($owner)->get("http://{$school->slug}.localhost/dashboard")->assertOk();
    }

    /** User report 2026-07-28: the parent-facing Exam Timetable rendered as a bare default table, visually inconsistent with the weekly timetable's own tt-grid styling right next to it in the nav. */
    public function test_the_parent_facing_exam_timetable_reuses_the_weekly_grids_own_styled_classes(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'ttexamstyle');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $child = Student::create(['admission_number' => 'EX1', 'first_name' => 'Chika', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Obi', 'guardian_user_id' => $parentUser->id]);
        $paper = \App\Models\Tenant\ExamPaper::create(['name' => 'Paper 1', 'sequence' => 1, 'duration_minutes' => 60, 'is_break' => false, 'starts_at' => '08:00', 'ends_at' => '09:00']);
        \App\Models\Tenant\ExamTimetableEntry::create(['exam_date' => '2026-11-25', 'exam_paper_id' => $paper->id, 'scope_type' => 'class', 'scope_id' => $classArm->id, 'subject_id' => $subject->id]);
        app(TenantManager::class)->forget();

        $response = $this->actingAs($parentUser)->get(route('school.timetable.exam.mine', ['school' => $school->slug]));

        $response->assertOk()->assertSee('class="tt-grid table-stack"', false)->assertSee('Mathematics');
    }

    /**
     * Real bug (2026-09-11 multi-role accounts, user report: "a parent
     * who is a teacher cant see child timeables"): resolveMineGrid() used
     * to check Staff::where('user_id', ...) unconditionally, with no
     * regard for which role was actually active. A parent granted an
     * additional teaching role gets a real linked Staff row the moment
     * that grant happens (MultiRoleAccountService::ensureStaffRecord()),
     * so the Staff branch always won from then on -- the parent could
     * never see their child's schedule again, switched or not.
     */
    public function test_a_parent_who_also_holds_a_teaching_role_sees_the_right_grid_for_whichever_role_is_active(): void
    {
        [$school, $parentUser] = $this->schoolWithMember('parent', 'ttmultirole');

        app(TenantManager::class)->use($school);
        $section = Section::create(['name' => 'Secondary', 'sequence' => 1]);
        $class = SchoolClass::create(['section_id' => $section->id, 'name' => 'JSS 1', 'sequence' => 1]);
        $arm = Arm::create(['name' => 'A']);
        $classArm = ClassArm::create(['school_class_id' => $class->id, 'arm_id' => $arm->id]);
        $subject = Subject::create(['name' => 'Mathematics']);
        $period = TimetablePeriod::create(['section_id' => null, 'name' => 'Period 1', 'starts_at' => '08:00', 'ends_at' => '08:40', 'sequence' => 1, 'is_teaching_period' => true]);
        $child = Student::create(['admission_number' => 'MR1', 'first_name' => 'Chika', 'last_name' => 'Obi', 'current_class_arm_id' => $classArm->id]);
        StudentGuardian::create(['student_id' => $child->id, 'name' => 'Mrs. Obi', 'guardian_user_id' => $parentUser->id]);
        TimetableEntry::create(['class_arm_id' => $classArm->id, 'day_of_week' => 'monday', 'timetable_period_id' => $period->id, 'subject_id' => $subject->id]);
        app(TenantManager::class)->forget();

        // Before ever holding a second role: the parent sees the child's
        // real schedule, not an empty state. actingAs() is given a FRESH
        // User instance each time here, deliberately -- reusing the same
        // PHP object across calls (as actingAs() otherwise lets a test
        // do) would keep its roleInCache from the first request forever,
        // a test-only artifact that never happens in real production
        // (every genuine HTTP request resolves Auth::user() fresh, with
        // an empty cache) and would mask the exact bug this test exists
        // to catch.
        $this->actingAs(User::find($parentUser->id))->get(route('school.timetable.mine', ['school' => $school->slug]))
            ->assertOk()->assertSee('Mathematics');

        $teacherRole = $this->rbac->cloneTemplateRole($school, 'class-teacher');
        app(\App\Services\Accounts\MultiRoleAccountService::class)->grantRole($school, $parentUser, $teacherRole, $parentUser);

        // Still acting as Parent (never switched): the grant alone must
        // not silently steal the family view just because a Staff row
        // now exists -- this is the literal bug.
        $this->actingAs(User::find($parentUser->id))->get(route('school.timetable.mine', ['school' => $school->slug]))
            ->assertOk()->assertSee('Mathematics');

        // Switch into the granted Class Teacher role.
        $this->actingAs(User::find($parentUser->id))->post("http://{$school->slug}.localhost/session/switch-role", ['role_id' => $teacherRole->id])
            ->assertRedirect();

        // Now acting as Class Teacher: the personal teaching view shows
        // (this teacher has no class of their own yet, so no crash and
        // no leaked child data is what matters here).
        $asTeacher = $this->actingAs(User::find($parentUser->id))->get(route('school.timetable.mine', ['school' => $school->slug]));
        $asTeacher->assertOk()->assertDontSee('Mathematics');

        // Switch back to Parent: the child's schedule must be visible
        // again, not permanently lost now that a Staff row exists.
        $primaryRoleId = (int) $school->users()->where('users.id', $parentUser->id)->value('school_user.role_id');
        $this->actingAs(User::find($parentUser->id))->post("http://{$school->slug}.localhost/session/switch-role", ['role_id' => $primaryRoleId])
            ->assertRedirect();

        $this->actingAs(User::find($parentUser->id))->get(route('school.timetable.mine', ['school' => $school->slug]))
            ->assertOk()->assertSee('Mathematics');
    }
}
