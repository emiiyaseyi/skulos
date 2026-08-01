<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A short recording captured when the room noise crossed the
 * configured threshold. Path only, never bytes in the database.
 */
class CbtAudioClip extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['session_id', 'path', 'duration_seconds', 'noise_level', 'captured_at'];

    protected function casts(): array
    {
        return [
            'captured_at' => 'datetime',
            'duration_seconds' => 'integer',
            'noise_level' => 'integer',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'session_id');
    }
}
