<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One question's placement on one paper — sequence within the paper,
 * and an optional per-paper points override (the same question can be
 * worth different points on different papers).
 */
class CbtPaperQuestion extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['paper_id', 'question_id', 'sequence', 'points_override'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'points_override' => 'decimal:2',
        ];
    }

    public function paper(): BelongsTo
    {
        return $this->belongsTo(CbtPaper::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class);
    }
}
