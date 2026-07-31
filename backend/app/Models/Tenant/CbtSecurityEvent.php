<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One proctoring signal during a session — JS-only (tab switch,
 * fullscreen exit, copy/paste, right-click, Phase 2c) or AI-based (face
 * missing/mismatch, object detected, Phase 2e). The unified event log a
 * school reviews per session (Phase 2g's live monitor / session detail
 * screen), and the raw input to RiskScoringService's running total.
 */
class CbtSecurityEvent extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['session_id', 'type', 'severity', 'score_impact', 'occurred_at', 'meta'];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'meta' => 'array',
            'overridden' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(CbtSession::class, 'session_id');
    }
}
