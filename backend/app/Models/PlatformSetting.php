<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

/**
 * Platform level key/value configuration. Mirrors App\Models\Tenant\Setting
 * but lives in the platform database and applies to the whole platform
 * (currently: SMTP settings) rather than one school.
 */
class PlatformSetting extends Model
{
    protected $fillable = ['key', 'value'];

    protected function casts(): array
    {
        return ['value' => 'json'];
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
