<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Staff;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Services\Accounts\AccountProvisioningService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Student and Parent Portal logins have auto-provisioned at creation
 * since 2026-07-21, and Staff joined that pattern 2026-09-11 -- but
 * both only ever applied going forward, from the moment each fix
 * shipped. Anyone added before their fix landed (or, for students, via
 * a path that predates it) was left exactly as before: no login at
 * all, waiting on a manual "Create login" click that may never come.
 * User directive 2026-09-11: "this should affect all current staff,
 * student and parent accounts that has been created before this
 * changes." One pass, every already-provisioned school, using the
 * exact same provisioning calls (and the same default-password
 * Settings) the live "Add Staff"/"Add Student"/"Add Guardian" actions
 * already use, so a backfilled login is indistinguishable from one
 * created the normal way.
 */
class BackfillAccountLogins extends Command
{
    protected $signature = 'accounts:backfill-logins {--school= : Only this school\'s slug, instead of every provisioned school}';

    protected $description = 'Auto-provisions a login for every existing staff, student, and guardian record that does not already have one';

    public function handle(TenantManager $tenants, AccountProvisioningService $accounts): int
    {
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')
            ->when($this->option('school'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        if ($schools->isEmpty()) {
            $this->info('No matching provisioned schools.');

            return self::SUCCESS;
        }

        $totals = ['staff' => 0, 'students' => 0, 'guardians' => 0];

        foreach ($schools as $school) {
            $tenants->use($school);

            $staffPassword = Setting::grab('default_staff_password', config('skulos.default_staff_password'));
            foreach (Staff::whereNull('user_id')->get() as $staffMember) {
                $syntheticEmail = 'staff-'.$staffMember->id.'@staff.'.$school->slug.'.skulos.local';
                $result = $accounts->provisionAuto($school, $staffMember->fullName(), $staffMember->email, $staffMember->phone, 'teacher', $staffPassword, $syntheticEmail);
                $staffMember->update(['user_id' => $result['user']->id]);
                $totals['staff']++;
            }

            $studentPassword = Setting::grab('default_student_password', config('skulos.default_student_password'));
            foreach (Student::whereNull('user_id')->get() as $student) {
                $syntheticEmail = \Illuminate\Support\Str::slug($student->admission_number, '-').'@students.'.$school->slug.'.skulos.local';
                $result = $accounts->provisionAuto($school, $student->fullName(), null, null, 'student', $studentPassword, $syntheticEmail);
                $student->update(['user_id' => $result['user']->id]);
                $totals['students']++;
            }

            $parentPassword = Setting::grab('default_parent_password', config('skulos.default_parent_password'));
            foreach (StudentGuardian::whereNull('guardian_user_id')->get() as $guardian) {
                $syntheticEmail = 'guardian-'.$guardian->id.'@parents.'.$school->slug.'.skulos.local';
                $result = $accounts->provisionAuto($school, $guardian->name, $guardian->email, $guardian->phone, 'parent', $parentPassword, $syntheticEmail);
                // Same reasoning as StudentController::storeGuardian(): a
                // guardian sharing an email/phone with one already
                // provisioned for a sibling reuses that account rather
                // than diverging into a second one.
                if (! $result['isNew']) {
                    $guardian->update([
                        'phone' => $result['user']->phone ?: $guardian->phone,
                        'email' => ($result['user']->email && ! str_ends_with($result['user']->email, '.skulos.local')) ? $result['user']->email : $guardian->email,
                    ]);
                }
                $guardian->update(['guardian_user_id' => $result['user']->id]);
                $totals['guardians']++;
            }

            $tenants->forget();
        }

        $this->info("Backfilled {$totals['staff']} staff, {$totals['students']} student, and {$totals['guardians']} guardian login(s) across {$schools->count()} school(s).");

        return self::SUCCESS;
    }
}
