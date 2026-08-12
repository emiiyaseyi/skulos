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
        'require_audio_check', 'require_photo_id', 'require_face_match', 'require_screen_share',
        'require_environment_scan', 'snapshot_interval_seconds',
        'event_scores', 'risk_medium_threshold', 'risk_high_threshold', 'autosuspend_score',
        'break_min_minutes', 'break_max_minutes',
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
            'require_audio_check' => 'boolean',
            'require_photo_id' => 'boolean',
            'require_face_match' => 'boolean',
            'require_screen_share' => 'boolean',
            'require_environment_scan' => 'boolean',
            'snapshot_interval_seconds' => 'integer',
            'event_scores' => 'array',
            'risk_medium_threshold' => 'integer',
            'risk_high_threshold' => 'integer',
            'autosuspend_score' => 'integer',
            'break_min_minutes' => 'integer',
            'break_max_minutes' => 'integer',
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
