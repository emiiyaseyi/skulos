<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\ClassArm;
use App\Models\Tenant\House;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Models\Tenant\Subject;
use App\Models\Tenant\TeachingAssignment;
use App\Models\Tenant\Term;
use App\Models\User;
use App\Services\Academics\AcademicDefaults;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

/**
 * Fills every provisioned school with realistic demo data for stress and
 * data testing (user request 2026-07-11: "seeded data (admin, staff,
 * students, parents) to all the schools in my database so that each class
 * has a minimum of 20 pupils per class, there will be class teachers,
 * subject teachers, Principal etc... It should run for all classes so
 * each school will have it covered from nursery to secondary"). Safe to
 * re-run: every step tops up what is missing rather than duplicating what
 * already exists, so running it again after a school has grown organically
 * just fills the remaining gap up to the target.
 *
 * Scope deliberately stops at what the product can already do something
 * with: students get a house and one guardian (contact info only, no
 * platform login, since no parent portal screens exist yet); staff
 * (Principal, one class teacher per class, one subject teacher per
 * subject per section) get real platform User accounts and RBAC roles,
 * since staff login already works end to end through the existing
 * school-portal sign in, unlike parents.
 */
class SeedDemoSchoolData extends Command
{
    protected $signature = 'schools:seed-demo-data {school? : A specific school slug, otherwise every provisioned school} {--per-class=20 : Minimum students per class arm}';

    protected $description = 'Seed realistic demo admin/staff/students/parents data into schools, for stress and data testing';

    protected const DEMO_PASSWORD = 'DemoPass123!';

    /** Every email/phone already in use platform wide, loaded once so generated ones are checked in memory, not one DB round trip each (user request 2026-07-11: seeded contact info must read as real, not obviously synthetic). */
    protected array $usedEmails = [];

    protected array $usedPhones = [];

    /** Guardians already created for the school currently being seeded, so a later student can share one as a sibling (user request 2026-07-11: "did you factor parents that has more than one child"). Reset per school. */
    protected array $guardianPool = [];

    protected array $emailDomains = ['gmail.com', 'gmail.com', 'gmail.com', 'yahoo.com', 'yahoo.com', 'outlook.com', 'hotmail.com'];

    protected array $phonePrefixes = ['0803', '0806', '0810', '0813', '0816', '0703', '0706', '0813', '0814', '0903', '0906', '0705', '0802', '0708'];

    protected array $nigerianStates = [
        'Lagos', 'Oyo', 'Ogun', 'Kano', 'Kaduna', 'Rivers', 'Delta', 'Enugu', 'Anambra', 'Imo',
        'Abia', 'Edo', 'Plateau', 'Cross River', 'Akwa Ibom', 'Osun', 'Ondo', 'Ekiti', 'Kwara', 'Niger',
        'Borno', 'Bauchi', 'Sokoto', 'Katsina', 'Benue', 'Kogi', 'FCT Abuja',
    ];

    protected array $maleFirstNames = [
        'Chinedu', 'Emeka', 'Tunde', 'Segun', 'Ifeanyi', 'Obinna', 'Kelechi', 'Uchenna', 'Chukwuemeka', 'Adewale',
        'Babajide', 'Oluwaseun', 'Chibuike', 'Nnamdi', 'Yusuf', 'Ibrahim', 'Musa', 'Suleiman', 'Abubakar', 'Aliyu',
        'Femi', 'Kunle', 'Damola', 'Gbenga', 'Wale', 'Chidi', 'Ekene', 'Chiamaka', 'Onyekachi', 'Anthony',
    ];

    protected array $femaleFirstNames = [
        'Ngozi', 'Chiamaka', 'Amaka', 'Adaeze', 'Chidinma', 'Ifeoma', 'Adaobi', 'Nkechi', 'Uju', 'Blessing',
        'Funke', 'Bukola', 'Yetunde', 'Folake', 'Aisha', 'Fatima', 'Zainab', 'Hauwa', 'Halima', 'Amina',
        'Ebele', 'Chinyere', 'Onyinye', 'Adanna', 'Temitope', 'Bisi', 'Ronke', 'Grace', 'Faith', 'Mercy',
    ];

    protected array $surnames = [
        'Okafor', 'Okonkwo', 'Nwosu', 'Eze', 'Adeyemi', 'Adebayo', 'Bello', 'Yusuf', 'Mohammed', 'Abubakar',
        'Balogun', 'Afolabi', 'Ogunleye', 'Ojo', 'Fashola', 'Nwachukwu', 'Chukwu', 'Obi', 'Uzo', 'Ibrahim',
        'Sani', 'Garba', 'Danjuma', 'Musa', 'Umar', 'Okoro', 'Onyema', 'Nnaji', 'Ade', 'Olawale',
        'Akintola', 'Salami', 'Lawal', 'Suleiman', 'Attah', 'Etim', 'Effiong', 'Ekong', 'Udo', 'Bassey',
    ];

    public function handle(TenantManager $tenants, AcademicDefaults $defaults, Rbac $rbac): int
    {
        $slug = $this->argument('school');
        $perClass = max(1, (int) $this->option('per-class'));

        $schools = $slug
            ? School::where('slug', $slug)->whereNotNull('database')->whereNotNull('provisioned_at')->get()
            : School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        if ($schools->isEmpty()) {
            $this->error($slug ? "No provisioned school found for slug \"{$slug}\"." : 'No provisioned schools found.');

            return self::FAILURE;
        }

        $this->usedEmails = array_flip(User::pluck('email')->map(fn ($e) => strtolower($e))->all());
        $this->usedPhones = array_flip(User::whereNotNull('phone')->pluck('phone')->all());

        foreach ($schools as $school) {
            $this->line("Seeding {$school->name} ({$school->slug})...");
            $tenants->use($school);

            $this->guardianPool = [];

            DB::connection('tenant')->transaction(function () use ($school, $defaults, $rbac, $perClass) {
                $this->ensureCurrentSession();
                $result = $defaults->applyClassStructure(array_keys(config('skulos.default_class_structure')));
                $this->line("  class structure: {$result['classes']} class(es), {$result['subjects']} subject(s) touched.");

                $houses = $this->ensureHouses();
                $identifier = Setting::grab('parent_identifier', 'email');

                $this->ensureLeadership($school, $rbac);

                $armsSeeded = 0;
                $studentsCreated = 0;
                foreach (ClassArm::with('schoolClass.section')->get() as $arm) {
                    $studentsCreated += $this->topUpStudents($arm, $perClass, $houses, $identifier);
                    $this->ensureClassTeacher($arm, $school, $rbac);
                    $armsSeeded++;
                }
                $this->line("  class arms covered: {$armsSeeded}, new students: {$studentsCreated}.");

                $teachersCreated = $this->ensureSubjectTeachers($school, $rbac);
                $this->line("  subject teacher(s) created: {$teachersCreated}.");
            });

            $tenants->forget();
            $this->info("Done: {$school->slug}.");
        }

        $this->info('All requested schools seeded.');

        return self::SUCCESS;
    }

    protected function ensureCurrentSession(): void
    {
        if (AcademicSession::current()->exists()) {
            return;
        }

        $startYear = (int) now()->year;
        $yearLabel = $startYear.'/'.($startYear + 1);
        $session = AcademicSession::firstOrCreate(['name' => "{$yearLabel} Session"]);

        foreach (['First' => 1, 'Second' => 2, 'Third' => 3] as $ordinal => $sequence) {
            Term::firstOrCreate(
                ['academic_session_id' => $session->id, 'sequence' => $sequence],
                ['name' => "{$ordinal} Term {$yearLabel}"]
            );
        }

        $session->makeCurrent();
        Term::where('academic_session_id', $session->id)->orderBy('sequence')->first()?->makeCurrent();
    }

    protected function ensureHouses(): \Illuminate\Support\Collection
    {
        foreach (config('skulos.default_houses') as $name) {
            House::firstOrCreate(['name' => $name]);
        }

        return House::active()->orderBy('name')->get();
    }

    /**
     * A Principal leads Secondary (JSS + SS, which already share one
     * "Secondary" Section by design), a Head Teacher leads Nursery and
     * Primary (Creche/Toddler, Reception, Nursery, Primary), matching the
     * real Nigerian school hierarchy (user correction 2026-07-11: "the
     * principal only have access to JSS and SSS only, while its head
     * teacher that has access to Creche, Reception, Nursery and Primary
     * classes"). Each is only created if that tier actually has classes,
     * and the RBAC scope is broad for both (grants "*", like Principal
     * always had) since the RBAC engine does not yet support restricting
     * a role to specific sections, only the position title reflects the
     * intended scope for now, see the tracker for this gap.
     */
    protected function ensureLeadership(School $school, Rbac $rbac): void
    {
        $hasSecondary = \App\Models\Tenant\Section::where('name', 'Secondary')->whereHas('classes')->exists();
        $hasNurseryOrPrimary = \App\Models\Tenant\Section::whereIn('name', ['Nursery', 'Primary'])->whereHas('classes')->exists();

        if ($hasSecondary && ! Staff::where('position', 'Principal')->exists()) {
            $staff = $this->makeStaffWithLogin($school, $rbac, 'Principal', 'principal');
            $this->line("  principal (JSS & SS): {$staff->fullName()}");
        }

        if ($hasNurseryOrPrimary && ! Staff::where('position', 'Head Teacher')->exists()) {
            $staff = $this->makeStaffWithLogin($school, $rbac, 'Head Teacher', 'head-teacher');
            $this->line("  head teacher (Creche/Reception/Nursery/Primary): {$staff->fullName()}");
        }
    }

    /**
     * Tops this class arm up to $target students, never removing or
     * touching students who already exist on it.
     */
    protected function topUpStudents(ClassArm $arm, int $target, \Illuminate\Support\Collection $houses, string $identifier): int
    {
        $existing = Student::where('current_class_arm_id', $arm->id)->count();
        $needed = max(0, $target - $existing);
        if ($needed === 0) {
            return 0;
        }

        $label = $arm->label();
        $prefix = strtoupper(preg_replace('/[^A-Za-z0-9]/', '', $arm->schoolClass?->name ?? 'CLS'));
        // withTrashed(): Student is soft-deleted, a removed student's
        // admission number must still count toward the sequence or a
        // freshly generated one could collide with one still in the table.
        $nextSeq = Student::withTrashed()->where('admission_number', 'like', "{$prefix}/%")->count() + 1;

        for ($i = 0; $i < $needed; $i++) {
            $gender = random_int(0, 1) === 0 ? 'Male' : 'Female';
            [$first, $last] = $this->randomName($gender);
            $admission = sprintf('%s/%04d', $prefix, $nextSeq + $i);

            $state = $this->nigerianStates[array_rand($this->nigerianStates)];

            $student = Student::create([
                'admission_number' => $admission,
                'first_name' => $first,
                'last_name' => $last,
                'gender' => $gender,
                'date_of_birth' => $this->birthDateFor($label),
                'current_class_arm_id' => $arm->id,
                'house_id' => $houses->isNotEmpty() ? $houses->random()->id : null,
                'status' => Student::STATUS_ACTIVE,
                'nationality' => 'Nigerian',
                'state_of_origin' => $state,
                'address' => sprintf('%d %s Street, %s', random_int(1, 200), $this->surnames[array_rand($this->surnames)], $state),
            ]);

            $this->addGuardianFor($student, $identifier);
        }

        return $needed;
    }

    /**
     * ~1 in 4 new students reuse an already-created guardian from this
     * same school run instead of a fresh one, simulating a sibling
     * already enrolled, real families with more than one child at the
     * same school (user request 2026-07-11). The reused guardian's exact
     * name/email/phone is copied onto a new StudentGuardian row for this
     * student (not a shared foreign key, StudentGuardian has no such
     * relation), matching on identical contact info the same way the
     * platform's own "same parent" recognition works. The student's own
     * surname is updated to match, siblings normally share one.
     */
    protected function addGuardianFor(Student $student, string $identifier): void
    {
        if (count($this->guardianPool) >= 3 && random_int(1, 4) === 1) {
            $sibling = $this->guardianPool[array_rand($this->guardianPool)];
            $student->update(['last_name' => $sibling['surname']]);

            StudentGuardian::create([
                'student_id' => $student->id,
                'name' => $sibling['name'],
                'relationship' => $sibling['relationship'],
                'email' => $sibling['email'],
                'phone' => $sibling['phone'],
                'is_primary' => true,
            ]);

            return;
        }

        $gender = random_int(0, 1) === 0 ? 'Male' : 'Female';
        [$first, $last] = $this->randomName($gender, $student->last_name);
        $relationship = $gender === 'Male' ? 'Father' : 'Mother';
        $email = $identifier === 'email' || random_int(0, 1) === 0 ? $this->realisticEmail($first, $last) : null;
        $phone = $identifier === 'phone' || random_int(0, 1) === 0 ? $this->realisticPhone() : null;

        StudentGuardian::create([
            'student_id' => $student->id,
            'name' => "{$first} {$last}",
            'relationship' => $relationship,
            'email' => $email,
            'phone' => $phone,
            'is_primary' => true,
        ]);

        $this->guardianPool[] = [
            'name' => "{$first} {$last}", 'surname' => $last, 'relationship' => $relationship,
            'email' => $email, 'phone' => $phone,
        ];
    }

    protected function ensureClassTeacher(ClassArm $arm, School $school, Rbac $rbac): void
    {
        if ($arm->class_teacher_staff_id) {
            return;
        }

        $staff = $this->makeStaffWithLogin($school, $rbac, 'Class Teacher', 'class-teacher');
        $arm->update(['class_teacher_staff_id' => $staff->id]);
    }

    /**
     * One subject teacher per unique subject per section (not one per
     * class), matching how a real school actually staffs subjects, e.g.
     * one Mathematics teacher covering every JSS arm rather than one per
     * arm. Skips a subject that already has a teacher assigned anywhere
     * in that section.
     */
    protected function ensureSubjectTeachers(School $school, Rbac $rbac): int
    {
        $created = 0;

        foreach (\App\Models\Tenant\Section::with('classes.arms')->get() as $section) {
            $armIds = $section->classes->flatMap(fn ($c) => $c->arms->pluck('id'));
            if ($armIds->isEmpty()) {
                continue;
            }

            $subjectIds = Subject::whereHas('classes', fn ($q) => $q->whereIn('school_classes.id', $section->classes->pluck('id')))
                ->pluck('id');

            foreach ($subjectIds as $subjectId) {
                $alreadyAssigned = TeachingAssignment::where('subject_id', $subjectId)
                    ->whereIn('class_arm_id', $armIds)
                    ->exists();
                if ($alreadyAssigned) {
                    continue;
                }

                $staff = $this->makeStaffWithLogin($school, $rbac, 'Subject Teacher', 'subject-teacher');
                foreach ($armIds as $armId) {
                    TeachingAssignment::firstOrCreate([
                        'staff_id' => $staff->id,
                        'subject_id' => $subjectId,
                        'class_arm_id' => $armId,
                    ]);
                }
                $created++;
            }
        }

        return $created;
    }

    protected function makeStaffWithLogin(School $school, Rbac $rbac, string $position, string $roleSlug): Staff
    {
        $gender = random_int(0, 1) === 0 ? 'Male' : 'Female';
        [$first, $last] = $this->randomName($gender);
        $email = $this->realisticEmail($first, $last);

        $user = User::create([
            'name' => "{$first} {$last}",
            'email' => $email,
            'phone' => $this->realisticPhone(),
            'password' => self::DEMO_PASSWORD,
        ]);

        $role = $rbac->cloneTemplateRole($school, $roleSlug);
        $school->users()->syncWithoutDetaching([$user->id => ['role_id' => $role->id, 'status' => 'active']]);

        // withTrashed(): Staff is soft-deleted, so a removed staff member's
        // id must still count toward the sequence, otherwise a freshly
        // generated id could collide with one still sitting in the table.
        $nextSeq = Staff::withTrashed()->count() + 1;

        return Staff::create([
            'staff_id' => sprintf('STF/%04d', $nextSeq),
            'first_name' => $first,
            'last_name' => $last,
            'gender' => $gender,
            'email' => $email,
            'phone' => $user->phone,
            'position' => $position,
            'employed_on' => now()->subDays(random_int(30, 1500)),
            'status' => Staff::STATUS_ACTIVE,
            'user_id' => $user->id,
        ]);
    }

    protected function randomName(string $gender, ?string $forcedSurname = null): array
    {
        $pool = $gender === 'Male' ? $this->maleFirstNames : $this->femaleFirstNames;
        $first = $pool[array_rand($pool)];
        $last = $forcedSurname ?? $this->surnames[array_rand($this->surnames)];

        return [$first, $last];
    }

    /**
     * A realistic personal email, e.g. "chinedu.okafor14@gmail.com", not a
     * synthetic tagged address. Not every generated address needs to be
     * globally unique (only the ones backing a platform User account do),
     * but this always produces one, checked against every email already
     * in use, so it is safe to use for either case.
     */
    protected function realisticEmail(string $first, string $last): string
    {
        $domain = $this->emailDomains[array_rand($this->emailDomains)];
        $cleanFirst = preg_replace('/[^a-z]/', '', strtolower($first));
        $cleanLast = preg_replace('/[^a-z]/', '', strtolower($last));
        $base = "{$cleanFirst}.{$cleanLast}";

        $email = "{$base}@{$domain}";
        $suffix = 0;
        while (isset($this->usedEmails[$email])) {
            $suffix++;
            $email = "{$base}{$suffix}@{$domain}";
        }
        $this->usedEmails[$email] = true;

        return $email;
    }

    /**
     * A realistic Nigerian mobile number, e.g. "08031234567", drawn from
     * real carrier prefixes and checked against every phone already in
     * use so it never collides with the platform unique constraint.
     */
    protected function realisticPhone(): string
    {
        do {
            $prefix = $this->phonePrefixes[array_rand($this->phonePrefixes)];
            $phone = $prefix.str_pad((string) random_int(0, 9999999), 7, '0', STR_PAD_LEFT);
        } while (isset($this->usedPhones[$phone]));
        $this->usedPhones[$phone] = true;

        return $phone;
    }

    /**
     * Ages per class (user request 2026-07-11, precise): Creche/Reception
     * 0-2, Nursery 1 age 3, Nursery 2 age 4, Primary starts at 5 (one
     * year per grade through Primary 6), JSS 1 starts at 10 (one year per
     * grade through JSS 3), Senior Secondary spans ages 14-18 across its
     * three grades. Primary/JSS grade numbers are read from the class
     * label itself (e.g. "Primary 4", "JSS 2"), not assumed, so a school
     * with custom class names outside this pattern still gets a sane
     * fallback rather than a wrong age.
     */
    protected function birthDateFor(string $classLabel): \Illuminate\Support\Carbon
    {
        $label = strtolower($classLabel);
        $grade = (int) (preg_match('/(\d+)/', $label, $m) ? $m[1] : 0);

        $age = match (true) {
            str_contains($label, 'creche') || str_contains($label, 'reception') || str_contains($label, 'toddler') => random_int(0, 2),
            str_contains($label, 'nursery') && $grade === 1 => 3,
            str_contains($label, 'nursery') && $grade === 2 => 4,
            str_contains($label, 'nursery') => random_int(3, 4),
            str_contains($label, 'primary') && $grade >= 1 && $grade <= 6 => 4 + $grade,
            str_contains($label, 'jss') && $grade >= 1 && $grade <= 3 => 9 + $grade,
            str_contains($label, 'jss') => random_int(10, 12),
            (str_contains($label, 'sss') || str_contains($label, 'ss')) && $grade === 1 => random_int(14, 15),
            (str_contains($label, 'sss') || str_contains($label, 'ss')) && $grade === 2 => random_int(15, 17),
            (str_contains($label, 'sss') || str_contains($label, 'ss')) && $grade === 3 => random_int(16, 18),
            str_contains($label, 'sss') || str_contains($label, 'ss') => random_int(14, 18),
            default => random_int(5, 18),
        };

        return now()->subYears($age)->subDays(random_int(0, 364));
    }
}
