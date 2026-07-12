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

        $permission = Permission::where('code', $code)->first();
        if (! $permission) {
            return false; // unknown code is never grantable
        }

        // Per-user override: deny always wins, allow grants beyond the role.
        $override = DB::table('user_permissions')
            ->where('school_id', $school->id)
            ->where('user_id', $user->id)
            ->where('permission_id', $permission->id)
            ->value('mode');

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
        $module = Module::where('code', $moduleCode)->first();
        if (! $module) {
            return Module::STATE_DISABLED; // unknown module: never on
        }

        $states = DB::table('module_states')
            ->where('school_id', $school->id)
            ->where('module_id', $module->id)
            ->get()
            ->keyBy(fn ($row) => $row->scope_type.':'.($row->scope_id ?? ''));

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

        return $module->defaultState();
    }

    /**
     * Set a module's state at school, role, or user scope.
     */
    public function setModuleState(School $school, string $moduleCode, string $state, string $scopeType = 'school', ?int $scopeId = null): void
    {
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
