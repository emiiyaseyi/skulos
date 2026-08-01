<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\MorphTo;

/**
 * SKULOS Assessment Hub, Phase 2d: progress through the pre-exam wizard
 * for one taker attempting one assessment. See the creating migration's
 * own docblock for why this is a separate table from CbtSession, not a
 * few extra columns bolted onto it.
 */
class CbtPrecheckSession extends Model
{
    use Auditable;

    public const STATUS_IN_PROGRESS = 'in_progress';

    public const STATUS_COMPLETED = 'completed';

    public const STATUS_ABANDONED = 'abandoned';

    protected $connection = 'tenant';

    protected $fillable = [
        'assessment_id', 'takeable_type', 'takeable_id', 'current_step', 'steps_completed',
        'photo_id_path', 'face_capture_path', 'environment_video_path', 'device_checks',
    ];

    protected function casts(): array
    {
        return [
            'steps_completed' => 'array',
            'device_checks' => 'array',
            'completed_at' => 'datetime',
        ];
    }

    public function assessment(): BelongsTo
    {
        return $this->belongsTo(CbtAssessment::class, 'assessment_id');
    }

    public function takeable(): MorphTo
    {
        return $this->morphTo();
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'cbt_session_id');
    }

    /**
     * The steps this particular attempt actually has to pass through, in
     * fixed order — derived live from the assessment's own security
     * profile every time, never cached on the row itself, so a profile
     * edited mid-wizard is honoured rather than a stale snapshot.
     */
    public function activeSteps(): array
    {
        $profile = $this->assessment->securityProfile;
        $all = array_keys(config('skulos.precheck_wizard_steps', []));

        return array_values(array_filter($all, function (string $step) use ($profile) {
            return match ($step) {
                'camera' => $profile->require_camera_check,
                'audio' => $profile->require_audio_check,
                'photoid' => $profile->require_photo_id,
                'face' => $profile->require_face_match,
                'keystroke' => $profile->require_face_match,
                'screenshare' => $profile->require_screen_share,
                'monitor' => $profile->require_object_detection,
                'environment' => $profile->require_environment_scan,
                'fullscreen' => $profile->require_fullscreen,
                default => true,
            };
        }));
    }

    public function isStepCompleted(string $step): bool
    {
        return in_array($step, $this->steps_completed ?? [], true);
    }

    public function nextStepAfter(string $step): ?string
    {
        $steps = $this->activeSteps();
        $index = array_search($step, $steps, true);

        if ($index === false || ! array_key_exists($index + 1, $steps)) {
            return null;
        }

        return $steps[$index + 1];
    }
}
