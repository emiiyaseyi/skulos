<?php

namespace App\Services\Rbac;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use App\Models\School;
use App\Models\User;
use Illuminate\Support\Facades\DB;

/**
 * Permission + module-state resolution. Everything here is deny-by-default:
 * no membership, no role, unknown code, suspended membership — all resolve
 * to "no".
 *
 * Permission precedence:  super admin > user deny > user allow > role grant > deny
 * Module-state precedence: user > role > school > catalog default
 */
class Rbac
{
    /**
     * Instance-level memoization (2026-08-13, a real performance finding
     * while investigating slow page loads: nav-school.blade.php alone
     * calls check() 16+ times per render, and check() itself does 2-3
     * fresh queries every single call even for values that never change
     * within one request — Permission-by-code, the user_permissions
     * override row, and the module/module_states lookups moduleState()
     * repeats per code even though many codes share one module.
     * Measured at 153 real SQL queries for those 16 checks before this
     * fix. Rbac is bound as a singleton in AppServiceProvider so every
     * app(Rbac::class)/constructor-injected Rbac call within one request
     * shares this same cache — safe because Laravel boots a fresh
     * container (and so a fresh Rbac instance) per request/test, never
     * reused across requests the way a static property would be.
     */
    protected array $permissionCache = [];

    protected array $moduleCache = [];

    protected array $moduleStatesCache = [];

    protected array $overrideCache = [];

    protected function permissionByCode(string $code): ?Permission
    {
        if (! array_key_exists($code, $this->permissionCache)) {
            $this->permissionCache[$code] = Permission::where('code', $code)->first();
        }

        return $this->permissionCache[$code];
    }

    /**
     * Can this user perform {code} (e.g. "students.bulk") in this school?
     */
    public function check(User $user, School $school, string $code): bool
    {
        if ($user->is_super_admin) {
            return true;
        }

        if (! $user->isMemberOf($school)) {
            return false;
        }

        $permission = $this->permissionByCode($code);
        if (! $permission) {
            return false; // unknown code is never grantable
        }

        // Per-user override: deny always wins, allow grants beyond the role.
        $overrideKey = "{$school->id}:{$user->id}:{$permission->id}";
        if (! array_key_exists($overrideKey, $this->overrideCache)) {
            $this->overrideCache[$overrideKey] = DB::table('user_permissions')
                ->where('school_id', $school->id)
                ->where('user_id', $user->id)
                ->where('permission_id', $permission->id)
                ->value('mode');
        }
        $override = $this->overrideCache[$overrideKey];

        if ($override === 'deny') {
            return false;
        }
        if ($override === 'allow') {
            return true;
        }

        $role = $user->roleIn($school);
        if (! $role) {
            return false;
        }

        if (! $role->permissions()->whereKey($permission->id)->exists()) {
            return false;
        }

        // A permission's module is always the part of its code before the
        // first dot (RbacSeeder generates every code as "{module}.{action}"
        // from config('skulos.modules') keys, so this is exact, not a
        // guess). Found 2026-07-11: changing a school's plan updated
        // Module state via setModuleState() correctly, but nothing ever
        // consulted it here, so a downgraded/never-purchased module's
        // permissions kept working regardless. disabled/hidden always
        // deny; readonly only allows .view actions, not mutating ones.
        $moduleCode = strstr($code, '.', true) ?: $code;
        $state = $this->moduleState($school, $moduleCode, $user);

        if ($state === Module::STATE_DISABLED || $state === Module::STATE_HIDDEN) {
            return false;
        }
        if ($state === Module::STATE_READONLY && ! str_ends_with($code, '.view')) {
            return false;
        }

        return true;
    }

    /**
     * Resolve a module's effective state for a user in a school:
     * enabled | disabled | hidden | readonly.
     */
    public function moduleState(School $school, string $moduleCode, ?User $user = null): string
    {
        if (! array_key_exists($moduleCode, $this->moduleCache)) {
            $this->moduleCache[$moduleCode] = Module::where('code', $moduleCode)->first();
        }
        $module = $this->moduleCache[$moduleCode];
        if (! $module) {
            return Module::STATE_DISABLED; // unknown module: never on
        }

        $statesKey = "{$school->id}:{$module->id}";
        if (! array_key_exists($statesKey, $this->moduleStatesCache)) {
            $this->moduleStatesCache[$statesKey] = DB::table('module_states')
                ->where('school_id', $school->id)
                ->where('module_id', $module->id)
                ->get()
                ->keyBy(fn ($row) => $row->scope_type.':'.($row->scope_id ?? ''));
        }
        $states = $this->moduleStatesCache[$statesKey];

        if ($user) {
            if ($hit = $states->get('user:'.$user->id)) {
                return $hit->state;
            }

            $role = $user->roleIn($school);
            if ($role && ($hit = $states->get('role:'.$role->id))) {
                return $hit->state;
            }
        }

        if ($hit = $states->get('school:')) {
            return $hit->state;
        }

        // No explicit module_states override anywhere (the common case:
        // module_states rows are only ever written when a platform admin
        // visits Billing and explicitly assigns/changes a school's plan,
        // BillingController::assignPlan()). Without this, a school that
        // has never been through that one specific admin action, which is
        // every school provisioned via the self-service trial flow, and
        // every factory-created test school, would have zero
        // module_states rows and every non-core module would silently
        // read as disabled regardless of what its plan actually promises
        // (found 2026-07-17 building the Foundation Finance slice, the
        // first non-core module with a real permission-gated screen
        // behind it; every prior non-core module, Library/Hostel/etc.,
        // is still "Soon" everywhere, so this gap never mattered before).
        // Falls back to the school's plan the same way
        // School::hasFeature() already does, not a second, inconsistent
        // fallback rule.
        $plan = $school->plan ?? \App\Models\Plan::where('is_default', true)->first();
        if (in_array($moduleCode, $plan?->modules ?? [], true)) {
            return Module::STATE_ENABLED;
        }

        return $module->defaultState();
    }

    /**
     * Set a module's state at school, role, or user scope.
     */
    public function setModuleState(School $school, string $moduleCode, string $state, string $scopeType = 'school', ?int $scopeId = null): void
    {
        // Clears the WHOLE cache, not just this one module's key: a
        // school-scope write can change the effective state for every
        // module-state lookup already cached under a stale reading
        // (2026-08-13, caught by a real test failure — Rbac is now a
        // singleton, so a controller action that changes a module's
        // state and then immediately re-checks permissions in the same
        // request/test method would otherwise see the pre-change value).
        $this->moduleStatesCache = [];
        $module = Module::where('code', $moduleCode)->firstOrFail();

        if (! in_array($state, [Module::STATE_ENABLED, Module::STATE_DISABLED, Module::STATE_HIDDEN, Module::STATE_READONLY], true)) {
            throw new \InvalidArgumentException("Invalid module state [{$state}].");
        }
        if (! in_array($scopeType, ['school', 'role', 'user'], true)) {
            throw new \InvalidArgumentException("Invalid scope type [{$scopeType}].");
        }
        if ($scopeType !== 'school' && $scopeId === null) {
            throw new \InvalidArgumentException('Role/user scoped states need a scope id.');
        }

        DB::table('module_states')->updateOrInsert(
            [
                'school_id' => $school->id,
                'module_id' => $module->id,
                'scope_type' => $scopeType,
                'scope_id' => $scopeType === 'school' ? null : $scopeId,
            ],
            [
                'state' => $state,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * Grant or deny a single permission for one user in one school
     * (the per-user override layer).
     */
    public function overridePermission(School $school, User $user, string $code, string $mode): void
    {
        if (! in_array($mode, ['allow', 'deny'], true)) {
            throw new \InvalidArgumentException("Invalid override mode [{$mode}].");
        }

        // Same reasoning as setModuleState() above — clears the whole
        // cache rather than trying to compute the one affected key,
        // since this write invalidates check()'s own overrideCache for
        // this user/school regardless of which permission changed.
        $this->overrideCache = [];
        $permission = Permission::where('code', $code)->firstOrFail();

        DB::table('user_permissions')->updateOrInsert(
            [
                'school_id' => $school->id,
                'user_id' => $user->id,
                'permission_id' => $permission->id,
            ],
            [
                'mode' => $mode,
                'updated_at' => now(),
                'created_at' => now(),
            ]
        );
    }

    /**
     * A staff member's one base role should not be their ceiling (user
     * request 2026-07-12: "a staff can be given more roles/features to
     * handle from the list of features... the school has under their
     * plans from billing"). Grants every permission code under one module
     * as a per-user override, the same set a role's "module.*" grant
     * would give, so "full access to Report Cards" here means exactly
     * what it would mean on a role.
     */
    public function grantModuleOverride(School $school, User $user, string $moduleCode): void
    {
        $codes = Permission::where('module', $moduleCode)->pluck('code');
        foreach ($codes as $code) {
            $this->overridePermission($school, $user, $code, 'allow');
        }
    }

    /** Removes every override (allow or deny) this user has for one module in this school, reverting to their role's default. */
    public function revokeModuleOverride(School $school, User $user, string $moduleCode): void
    {
        // Bypasses overridePermission() (a raw delete, not an
        // updateOrInsert per code), so it needs its own cache clear too.
        $this->overrideCache = [];
        DB::table('user_permissions')
            ->where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->whereIn('permission_id', Permission::where('module', $moduleCode)->pluck('id'))
            ->delete();
    }

    /** Whether this user has an extra, per-user "allow" override for any permission under this module (the module override toggle's current state). */
    public function hasModuleOverride(School $school, User $user, string $moduleCode): bool
    {
        return DB::table('user_permissions')
            ->where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->where('mode', 'allow')
            ->whereIn('permission_id', Permission::where('module', $moduleCode)->pluck('id'))
            ->exists();
    }

    /**
     * Copy a built-in template role (school_id null) into a school so the
     * school can customize it without touching the shared template.
     */
    public function cloneTemplateRole(School $school, string $templateSlug): Role
    {
        $template = Role::whereNull('school_id')->where('slug', $templateSlug)->firstOrFail();

        $existing = Role::where('school_id', $school->id)->where('slug', $templateSlug)->first();
        if ($existing) {
            return $existing;
        }

        $role = new Role([
            'name' => $template->name,
            'slug' => $template->slug,
            'description' => $template->description,
        ]);
        $role->school_id = $school->id;
        $role->save();

        $role->permissions()->sync($template->permissions()->pluck('permissions.id'));

        return $role;
    }
}
