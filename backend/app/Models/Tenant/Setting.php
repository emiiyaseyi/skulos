<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * Per-school key/value configuration (TD-007). Lives in the school's own
 * database — the "tenant" connection must be pointed at a school (via the
 * tenant middleware or TenantManager) before this model is touched.
 */
class Setting extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return [
            'value' => 'json',
        ];
    }

    public static function put(string $key, mixed $value): static
    {
        return static::updateOrCreate(['key' => $key], ['value' => $value]);
    }

    public static function grab(string $key, mixed $default = null): mixed
    {
        return static::where('key', $key)->first()?->value ?? $default;
    }
}
