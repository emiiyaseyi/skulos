<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * The exact question set one attempt was given, in that taker's
 * own order — what makes a randomised/shuffled assessment
 * re-renderable on resume and reviewable afterwards.
 */
class CbtSessionQuestion extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['session_id', 'question_id', 'sequence', 'points'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'points' => 'decimal:2',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'session_id');
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class, 'question_id');
    }
}
