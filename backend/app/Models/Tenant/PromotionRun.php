<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One admin-triggered run of the manual promotion flow (feedback list
 * 8/7/2026, item 9) — a persisted, revisitable report, distinct from the
 * automatic PromotionService::promote() that runs at end-of-session.
 */
class PromotionRun extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'term_id', 'to_term_id', 'threshold', 'run_by_user_id',
        'promoted_count', 'repeated_count', 'graduated_count', 'skipped_count',
    ];

    public function term(): BelongsTo
    {
        return $this->belongsTo(Term::class);
    }

    public function toTerm(): BelongsTo
    {
        return $this->belongsTo(Term::class, 'to_term_id');
    }

    public function items(): HasMany
    {
        return $this->hasMany(PromotionRunItem::class);
    }

    /**
     * Built manually, not a plain belongsTo(): a bare belongsTo() would
     * inherit this model's tenant connection onto User (a platform
     * model), the same reasoning StudentProfileChangeRequest::
     * submittedBy() already documents.
     */
    public function runBy(): BelongsTo
    {
        $related = (new User())->setConnection(config('database.default'));

        return $this->newBelongsTo($related->newQuery(), $this, 'run_by_user_id', 'id', 'runBy');
    }
}
