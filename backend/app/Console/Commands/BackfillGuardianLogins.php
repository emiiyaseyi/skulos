<?php

namespace App\Console\Commands;

use App\Models\School;
use App\Models\Tenant\Setting;
use App\Models\Tenant\StudentGuardian;
use App\Services\Accounts\AccountProvisioningService;
use App\Services\Tenancy\TenantManager;
use Illuminate\Console\Command;

/**
 * Every guardian added through the real Add Guardian form has auto
 * provisioned a Parent Portal login since 2026-07-21
 * (StudentController::storeGuardian). schools:seed-demo-data's own guardian
 * step already matches that (guardians it creates get a login too), but
 * only for guardians created by a run of the command from that point
 * onward: it only ever tops classes up to their target headcount, so a
 * school already at target (every demo-seeded school, since they were all
 * seeded before this existed) never has its already-existing guardian rows
 * revisited. Found live 2026-07-25 on greenfield: 319 of 320 guardians had
 * no login at all, so a parent broadcast reached exactly one real account
 * (user report: "why did only one person get it").
 *
 * Reuses AccountProvisioningService::provisionAuto(), the exact same code
 * path the real Add Guardian form calls, so a guardian who already shares
 * an email/phone with another child's guardian row (a sibling) is found
 * and reused rather than duplicated, identical to how the live form
 * behaves. Idempotent and safe to re-run: only ever touches rows where
 * guardian_user_id is still null.
 */
class BackfillGuardianLogins extends Command
{
    protected $signature = 'schools:backfill-guardian-logins {school? : A specific school slug, otherwise every provisioned school}';

    protected $description = 'Provisions a Parent Portal login for any guardian who does not already have one, the same way the real Add Guardian form does';

    public function handle(TenantManager $tenants, AccountProvisioningService $accounts): int
    {
        $slug = $this->argument('school');

        $schools = $slug
            ? School::where('slug', $slug)->whereNotNull('database')->whereNotNull('provisioned_at')->get()
            : School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        if ($schools->isEmpty()) {
            $this->error($slug ? "No provisioned school found for slug \"{$slug}\"." : 'No provisioned schools found.');

            return self::FAILURE;
        }

        foreach ($schools as $school) {
            $tenants->use($school);

            $missing = StudentGuardian::whereNull('guardian_user_id')->get();
            if ($missing->isEmpty()) {
                $this->line("{$school->name} ({$school->slug}): every guardian already has a login.");

                continue;
            }

            $password = Setting::grab('default_parent_password', config('skulos.default_parent_password'));
            $provisioned = 0;
            $reused = 0;

            foreach ($missing as $guardian) {
                $syntheticEmail = 'guardian-'.$guardian->id.'@parents.'.$school->slug.'.skulos.local';
                $result = $accounts->provisionAuto($school, $guardian->name, $guardian->email, $guardian->phone, 'parent', $password, $syntheticEmail);
                $guardian->update(['guardian_user_id' => $result['user']->id]);
                $result['isNew'] ? $provisioned++ : $reused++;
            }

            $this->line("{$school->name} ({$school->slug}): {$provisioned} new login(s) provisioned, {$reused} reused from a sibling's existing login.");
        }

        $tenants->forget();

        return self::SUCCESS;
    }
}
