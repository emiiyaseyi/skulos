<?php

namespace Database\Seeders;

use App\Models\Module;
use App\Models\Permission;
use App\Models\Role;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

/**
 * Syncs the module catalog, permission catalog, and built-in role templates
 * from config/skulos.php. Idempotent — safe to re-run on every deploy;
 * schools' own cloned/customized roles are never touched.
 */
class RbacSeeder extends Seeder
{
    public function run(): void
    {
        $this->syncModules();
        $permissionsByCode = $this->syncPermissions();
        $this->syncTemplateRoles($permissionsByCode);
    }

    protected function syncModules(): void
    {
        foreach (config('skulos.modules') as $code => $def) {
            Module::updateOrCreate(
                ['code' => $code],
                ['name' => $def['name'], 'is_core' => $def['core']]
            );
        }
    }

    /**
     * @return array<string, Permission>
     */
    protected function syncPermissions(): array
    {
        $actions = config('skulos.permission_actions');
        $extra = config('skulos.extra_permissions');
        $byCode = [];

        foreach (array_keys(config('skulos.modules')) as $module) {
            $codes = array_map(fn ($a) => "{$module}.{$a}", $actions);
            foreach ($extra[$module] ?? [] as $extraAction) {
                $codes[] = "{$module}.{$extraAction}";
            }

            foreach ($codes as $code) {
                $action = Str::after($code, '.');
                $byCode[$code] = Permission::updateOrCreate(
                    ['code' => $code],
                    [
                        'module' => $module,
                        'label' => Str::headline($action).' — '.config("skulos.modules.{$module}.name"),
                    ]
                );
            }
        }

        return $byCode;
    }

    /**
     * @param array<string, Permission> $permissionsByCode
     */
    protected function syncTemplateRoles(array $permissionsByCode): void
    {
        foreach (config('skulos.roles') as $slug => $def) {
            $role = Role::whereNull('school_id')->where('slug', $slug)->first();

            if (! $role) {
                $role = new Role(['name' => $def['name'], 'slug' => $slug]);
                $role->save(); // school_id stays null: template
            } else {
                $role->update(['name' => $def['name']]);
            }

            $role->permissions()->sync(
                $this->resolveGrants($def['grants'], $permissionsByCode)
            );
        }
    }

    /**
     * Expand "*" and "module.*" grant patterns into permission ids.
     *
     * @param list<string> $grants
     * @param array<string, Permission> $permissionsByCode
     * @return list<int>
     */
    protected function resolveGrants(array $grants, array $permissionsByCode): array
    {
        $ids = [];

        foreach ($grants as $grant) {
            if ($grant === '*') {
                return array_map(fn (Permission $p) => $p->id, array_values($permissionsByCode));
            }

            if (Str::endsWith($grant, '.*')) {
                $module = Str::before($grant, '.*');
                foreach ($permissionsByCode as $code => $permission) {
                    if (Str::startsWith($code, $module.'.')) {
                        $ids[] = $permission->id;
                    }
                }

                continue;
            }

            if (isset($permissionsByCode[$grant])) {
                $ids[] = $permissionsByCode[$grant]->id;
            }
        }

        return array_values(array_unique($ids));
    }
}
