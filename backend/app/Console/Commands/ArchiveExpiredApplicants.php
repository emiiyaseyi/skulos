<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\Applicant;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Admissions module (user directive 2026-07-30: "user is lock out and
 * profile is archive" once an applicant's temporary window passes).
 * EnsureApplicantActive already flips an individual applicant to
 * locked_out the moment they try to use an expired session, but an
 * applicant who never comes back would otherwise sit at "active" forever
 * with nothing to ever notice the expiry, so this sweeps every school
 * daily and archives anyone past their window, logged in or not.
 */
class ArchiveExpiredApplicants extends Command
{
    protected $signature = 'admissions:archive-expired-applicants';

    protected $description = 'Lock out and archive applicant accounts past their temporary application window';

    public function handle(TenantManager $tenants): int
    {
        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        foreach ($schools as $school) {
            try {
                $tenants->use($school);
            } catch (\Throwable $e) {
                $this->error("Skipping {$school->slug}: {$e->getMessage()}");

                continue;
            }

            $archived = Applicant::where('account_status', Applicant::STATUS_ACTIVE)
                ->where('expires_at', '<', now())
                ->update(['account_status' => Applicant::STATUS_ARCHIVED]);

            $tenants->forget();

            if ($archived) {
                $this->info("{$school->slug}: archived {$archived} expired applicant(s).");
            }
        }

        return self::SUCCESS;
    }
}
