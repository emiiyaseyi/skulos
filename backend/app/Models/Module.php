<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Catalog entry for a platform module (Students, Library, ...). Seeded from
 * config/skulos.php. Per-school/role/user visibility lives in module_states
 * and is resolved by App\Services\Rbac\Rbac::moduleState().
 */
class Module extends Model
{
    public const STATE_ENABLED = 'enabled';
    public const STATE_DISABLED = 'disabled';
    public const STATE_HIDDEN = 'hidden';
    public const STATE_READONLY = 'readonly';

    protected $fillable = ['code', 'name', 'is_core'];

    protected function casts(): array
    {
        return [
            'is_core' => 'boolean',
        ];
    }

    public function defaultState(): string
    {
        return $this->is_core ? self::STATE_ENABLED : self::STATE_DISABLED;
    }

    /**
     * Optional (non core) modules, keyed by code, in the shape Billing and
     * School Admin screens expect. Reads the seeded DB catalog, not
     * config('skulos.modules') directly, so a module added later only
     * needs a reseed, never a code change at every call site.
     *
     * @return array<string, array{name: string, core: bool}>
     */
    public static function optional(): array
    {
        return static::where('is_core', false)->orderBy('name')->get()
            ->mapWithKeys(fn (self $m) => [$m->code => ['name' => $m->name, 'core' => $m->is_core]])
            ->all();
    }
}
