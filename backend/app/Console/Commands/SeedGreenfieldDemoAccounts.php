<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\User;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

/**
 * One-off setup (2026-08-15, user request): greenfield used as a live
 * demo school for prospective customers, one real read-only account per
 * major role. Reuses real already-imported staff/parent accounts (their
 * data is genuinely populated, a far better demo than an empty synthetic
 * record), only creating new logins for the two roles nothing suitable
 * already existed for (admin -- deliberately NOT the user's own real
 * qaowner@example.com working account, per their explicit direction;
 * student -- no SSS 1 student had a login provisioned locally at all).
 * Every account here gets is_demo_readonly=true (see
 * BlockDemoAccountMutations), scoped to just this one school membership.
 */
class SeedGreenfieldDemoAccounts extends Command
{
    protected $signature = 'schools:seed-greenfield-demo-accounts';

    protected $description = 'Create/flag the 5 read-only demo accounts (admin, class teacher, subject teacher, parent, student) for the greenfield demo school.';

    public function handle(TenantManager $tenants, Rbac $rbac): int
    {
        $school = School::where('slug', 'greenfield')->first();
        if (! $school) {
            $this->error('greenfield school not found.');

            return self::FAILURE;
        }

        $tenants->use($school);

        $this->attach($school, $rbac, 'demo.admin@skulosapp.com', 'Demo Admin', 'school-owner');
        $this->attach($school, $rbac, 'uju.balogun@outlook.com', null, 'class-teacher');
        $this->attach($school, $rbac, 'chidinma.akintola@yahoo.com', null, 'subject-teacher');
        $this->attach($school, $rbac, 'tunde.yusuf@gmail.com', null, 'parent');

        $student = DB::connection('tenant')->table('students')->where('id', 606)->first();
        if (! $student) {
            $this->error('Student #606 (Femi Adeyemi) not found -- check the id is still correct.');

            return self::FAILURE;
        }
        $studentEmail = 'demo.student@skulosapp.com';
        $studentUserId = $this->attach($school, $rbac, $studentEmail, 'Femi Adeyemi', 'student');
        DB::connection('tenant')->table('students')->where('id', 606)->update(['user_id' => $studentUserId]);
        $this->line("Linked student #606 (Femi Adeyemi) to {$studentEmail}.");

        $tenants->forget();

        $this->info('Done. All 5 demo accounts use password: password');

        return self::SUCCESS;
    }

    protected function attach(School $school, Rbac $rbac, string $email, ?string $name, string $roleSlug): int
    {
        $user = User::where('email', $email)->first();
        if (! $user) {
            $user = User::create([
                'name' => $name ?? $email,
                'email' => $email,
                'password' => Hash::make('password'),
                'must_change_password' => false,
            ]);
            $this->line("{$email}: created new account (#{$user->id}).");
        } else {
            $user->forceFill(['password' => Hash::make('password'), 'must_change_password' => false])->save();
            $this->line("{$email}: reusing existing account (#{$user->id}), password reset.");
        }

        $role = \App\Models\Role::where('school_id', $school->id)->where('slug', $roleSlug)->first()
            ?? $rbac->cloneTemplateRole($school, $roleSlug);

        DB::table('school_user')->updateOrInsert(
            ['user_id' => $user->id, 'school_id' => $school->id],
            ['role_id' => $role->id, 'status' => 'active', 'is_demo_readonly' => true, 'updated_at' => now(), 'created_at' => now()]
        );
        $this->line("  -> attached as '{$roleSlug}', read-only.");

        return $user->id;
    }
}
