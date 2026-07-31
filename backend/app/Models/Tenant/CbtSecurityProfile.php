<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One of a school's four fixed security tiers (Basic/Medium/High/
 * Maximum) a paper can be assigned. See config('skulos.default_cbt_
 * security_profiles')'s own docblock for why this is seeded once into a
 * real tenant table rather than read from config directly.
 */
class CbtSecurityProfile extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'name', 'require_fullscreen', 'block_copy_paste', 'block_right_click',
        'tab_switch_action', 'tab_switch_limit', 'require_camera_check', 'require_object_detection',
    ];

    protected function casts(): array
    {
        return [
            'require_fullscreen' => 'boolean',
            'block_copy_paste' => 'boolean',
            'block_right_click' => 'boolean',
            'tab_switch_limit' => 'integer',
            'require_camera_check' => 'boolean',
            'require_object_detection' => 'boolean',
        ];
    }

    public static function ensureSeeded(): void
    {
        if (static::query()->exists()) {
            return;
        }

        foreach (config('skulos.default_cbt_security_profiles', []) as $profile) {
            static::create($profile);
        }
    }
}
