<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One taker's answer to one question within a CbtSession. Grading
 * columns (is_correct/score_awarded/status/graded_by_user_id/graded_at/
 * notes) are deliberately NOT fillable, see the creating migration's
 * docblock — `answer` is the only field a taker's own auto-save can
 * ever touch.
 */
class CbtResponse extends Model
{
    use Auditable;

    public const STATUS_UNGRADED = 'ungraded';

    public const STATUS_AUTO_GRADED = 'auto_graded';

    public const STATUS_GRADED = 'graded';

    protected $connection = 'tenant';

    protected $fillable = ['session_id', 'question_id', 'answer'];

    protected function casts(): array
    {
        return [
            'answer' => 'array',
            'is_correct' => 'boolean',
            'score_awarded' => 'decimal:2',
            'graded_at' => 'datetime',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class);
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class);
    }
}
