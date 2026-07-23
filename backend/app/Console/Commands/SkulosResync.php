<?php

namespace App\Console\Commands;

use App\Models\Module;
use App\Models\Plan;
use App\Models\Role;
use App\Models\School;
use App\Models\SchoolAddOn;
use App\Services\Rbac\Rbac;
use App\Services\Tenancy\TenantManager;
use Database\Seeders\RbacSeeder;
use Illuminate\Console\Command;

/**
 * A single command for the three "a catalog change never reaches an
 * already-provisioned school on its own" gotchas documented in CLAUDE.md,
 * each found and fixed separately (tenant migrations, 2026-07-11;
 * extra_permissions on wildcard roles, 2026-07-11; module_states,
 * 2026-07-17), each requiring the same manual, easy-to-forget follow up:
 * run one specific command or tinker script against every school. This
 * wraps all three so the answer to "I changed a tenant migration /
 * permission catalog / module list, now what" is always the same one
 * command, run every time, rather than three commands remembered
 * separately (or not).
 *
 * Steps 1 and 2 are purely additive (a missing migration/permission is
 * added, nothing already granted is ever removed) and always run.
 *
 * Step 3 (module state resync) is opt in via --modules: unlike the other
 * two, BillingController::assignPlan()'s own sync logic resets EVERY
 * optional module to enabled/disabled purely from the plan's module list,
 * which would silently revoke a module an admin explicitly granted via
 * SchoolAddOn beyond the plan (grantAddOn()) if run blindly. This command
 * closes that gap rather than reproducing it: an active SchoolAddOn's own
 * module is preserved regardless of what the plan says.
 */
class SkulosResync extends Command
{
    protected $signature = 'skulos:resync {--modules : Also resync module states from each school\'s current plan, preserving active add-on grants}';

    protected $description = 'Reaches every already-provisioned school with the latest tenant migrations, wildcard role permissions, and (with --modules) plan module states, in one pass';

    public function handle(TenantManager $tenants, Rbac $rbac): int
    {
        $this->call('db:seed', ['--class' => RbacSeeder::class, '--force' => true]);

        $schools = School::whereNotNull('database')->whereNotNull('provisioned_at')->get();

        if ($schools->isEmpty()) {
            $this->info('No provisioned schools to resync.');

            return self::SUCCESS;
        }

        $this->info("Step 1/".($this->option('modules') ? '3' : '2').": tenant migrations");
        foreach ($schools as $school) {
            try {
                $tenants->migrate($school);
            } catch (\Throwable $e) {
                $this->error("Migration failed for {$school->slug}: {$e->getMessage()}");
            }
        }

        $this->info("Step 2/".($this->option('modules') ? '3' : '2').": wildcard role permission resync");
        $this->resyncWildcardRoles($schools);

        if ($this->option('modules')) {
            $this->info('Step 3/3: module state resync from current plan');
            $this->resyncModuleStates($schools, $rbac);
        }

        $this->info("Resync complete: {$schools->count()} school(s) checked.");

        return self::SUCCESS;
    }

    /**
     * A school that cloned a wildcard template role (school-owner,
     * school-admin, principal, head-teacher — config('skulos.roles') grant
     * === ['*']) before a new permission existed is frozen without it
     * forever, even though the template's own grant conceptually means
     * "everything". Additive only, and further scoped to permissions
     * CREATED AFTER the school's own role was last deliberately touched
     * (its `updated_at`, moved forward by RoleManagementController::
     * updateRolePermissions() every time a school edits that role's
     * permissions, in addition to the initial clone): a plain
     * "template has it, school role doesn't" diff cannot tell a genuinely
     * new permission apart from one the school explicitly unchecked, both
     * look identical as a missing row. Comparing against the timestamp of
     * the school's own last deliberate choice can: anything older than
     * that choice was already considered and correctly stays missing;
     * anything newer could not have been considered yet and is added.
     */
    protected function resyncWildcardRoles(\Illuminate\Support\Collection $schools): void
    {
        $wildcardSlugs = collect(config('skulos.roles', []))
            ->filter(fn ($def) => ($def['grants'] ?? []) === ['*'])
            ->keys();

        $templates = Role::whereNull('school_id')->whereIn('slug', $wildcardSlugs)->get()->keyBy('slug');

        foreach ($schools as $school) {
            foreach ($templates as $slug => $template) {
                $schoolRole = Role::where('school_id', $school->id)->where('slug', $slug)->first();
                if (! $schoolRole) {
                    continue; // school never cloned this role, nothing to resync
                }
                $newSince = $schoolRole->updated_at ?? $schoolRole->created_at;
                $newPermissionIds = $template->permissions()->where('permissions.created_at', '>', $newSince)->pluck('permissions.id');
                if ($newPermissionIds->isNotEmpty()) {
                    $schoolRole->permissions()->syncWithoutDetaching($newPermissionIds);
                }
            }
        }
    }

    protected function resyncModuleStates(\Illuminate\Support\Collection $schools, Rbac $rbac): void
    {
        $optionalModules = Module::optional();
        $defaultPlan = Plan::where('is_default', true)->first();

        foreach ($schools as $school) {
            $plan = $school->plan ?? $defaultPlan;
            $addOnModules = SchoolAddOn::where('school_id', $school->id)->get()
                ->map(fn (SchoolAddOn $a) => $a->catalogEntry()['module'] ?? null)
                ->filter()
                ->all();

            foreach ($optionalModules as $code => $meta) {
                $shouldEnable = in_array($code, $plan?->modules ?? [], true) || in_array($code, $addOnModules, true);
                $rbac->setModuleState($school, $code, $shouldEnable ? Module::STATE_ENABLED : Module::STATE_DISABLED);
            }
        }
    }
}
