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
}
