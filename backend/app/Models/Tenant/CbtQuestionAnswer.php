<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One answer option on a question. Mirrors the reference repo's
 * `learnguard_qbank_answers`: `fraction` carries partial credit (1 = a
 * fully correct option, 0 = wrong, anything between = partial), and
 * each option gets its own `feedback` shown back on review. This
 * replaces Phase 2a's `options`/`correct_answer` JSON pair, which
 * could express neither partial credit nor per-option feedback.
 */
class CbtQuestionAnswer extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['question_id', 'answer_text', 'fraction', 'feedback', 'sort_order'];

    protected function casts(): array
    {
        return [
            'fraction' => 'float',
            'sort_order' => 'integer',
        ];
    }

    public function question(): BelongsTo
    {
        return $this->belongsTo(CbtQuestion::class, 'question_id');
    }

    public function isCorrect(): bool
    {
        return $this->fraction > 0;
    }
}
