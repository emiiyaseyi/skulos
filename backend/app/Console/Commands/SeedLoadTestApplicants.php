<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\AcademicSession;
use App\Models\Tenant\Applicant;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Load-test fixture data ONLY — creates N real, pending_fee applicant
 * accounts whose own admission number doubles as their sign-in
 * password (see Applicant::canSignIn()'s own docblock), matching
 * exactly what loadtest/admission-assessment-load-test.js (repo root)
 * expects to sign in as. Never run this against a school with real
 * applicant data unless the prefix is scoped to something that cannot
 * collide with a genuine admission_number.
 *
 * Deliberately a separate command from cbt:seed-jss1-admission-
 * assessment: that one seeds real demo CONTENT meant to stay in place,
 * this one seeds disposable ACCOUNTS meant to be deleted again after a
 * load test run (see the --cleanup flag).
 */
class SeedLoadTestApplicants extends Command
{
    protected $signature = 'loadtest:seed-applicants
        {school : The school slug to seed into}
        {--count=100 : How many applicant accounts to create}
        {--start=1 : The first serial number, matching APPLICANT_START in the k6 script}
        {--prefix=APP/ : Reference number prefix, matching APPLICANT_PREFIX in the k6 script}
        {--class= : Name (or partial name) of the SchoolClass to assign every applicant to — must match whatever the target assessment is scoped to, if it is scoped at all}
        {--cleanup : Delete every applicant whose admission_number matches the given prefix instead of creating any}';

    protected $description = 'Seeds (or cleans up) disposable pending_fee applicant accounts for the k6 load test script';

    public function handle(TenantManager $tenants): int
    {
        $school = School::where('slug', $this->argument('school'))->first();
        if (! $school) {
            $this->error("No school found with slug '{$this->argument('school')}'.");

            return self::FAILURE;
        }

        $prefix = $this->option('prefix');
        $tenants->use($school);

        try {
            if ($this->option('cleanup')) {
                $deleted = Applicant::where('admission_number', 'like', $prefix.'%')->delete();
                $this->info("Deleted {$deleted} load-test applicant(s) matching '{$prefix}*'.");

                return self::SUCCESS;
            }

            $academicSession = AcademicSession::where('is_current', true)->first() ?? AcademicSession::first();
            if (! $academicSession) {
                $this->error('This school has no academic session at all — create one first.');

                return self::FAILURE;
            }

            $schoolClass = null;
            if ($this->option('class')) {
                $schoolClass = \App\Models\Tenant\SchoolClass::where('name', 'like', '%'.$this->option('class').'%')->first();
                if (! $schoolClass) {
                    $this->error("No class matching '{$this->option('class')}' found.");

                    return self::FAILURE;
                }
            } else {
                $schoolClass = \App\Models\Tenant\SchoolClass::first();
            }

            $count = (int) $this->option('count');
            $start = (int) $this->option('start');
            $created = 0;

            for ($i = $start; $i < $start + $count; $i++) {
                $reference = $prefix.str_pad((string) $i, 4, '0', STR_PAD_LEFT);
                if (Applicant::where('admission_number', $reference)->exists()) {
                    continue;
                }

                Applicant::forceCreate([
                    'name' => "Load Test Applicant {$i}",
                    'phone' => '0700'.str_pad((string) $i, 7, '0', STR_PAD_LEFT),
                    'admission_number' => $reference,
                    'session_id' => $academicSession->id,
                    'school_class_id' => $schoolClass?->id,
                    'account_status' => Applicant::STATUS_PENDING_FEE,
                    'expires_at' => now()->addDay(),
                ]);
                $created++;
            }

            $first = $prefix.str_pad((string) $start, 4, '0', STR_PAD_LEFT);
            $last = $prefix.str_pad((string) ($start + $count - 1), 4, '0', STR_PAD_LEFT);
            $this->info("Created {$created} load-test applicant(s) ({$first} .. {$last}), each signing in with its own reference number as both identifier and password.");
            if ($schoolClass) {
                $this->info("Assigned to class: {$schoolClass->name}. Make sure this matches the target assessment's applicant_school_class_id (or that the assessment has no class restriction at all), or every start attempt will correctly 403 as ineligible.");
            }

            return self::SUCCESS;
        } finally {
            $tenants->forget();
        }
    }
}
