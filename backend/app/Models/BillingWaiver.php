<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A waived billing period for one school (user request 2026-07-09: "how
 * to make a term free"), e.g. a free trial term or a goodwill waiver.
 * "label" is free text since terms are tenant scoped data and can't be a
 * real foreign key from the platform database.
 */
class BillingWaiver extends Model
{
    use Auditable;

    protected $fillable = ['school_id', 'label', 'note', 'granted_by'];

    public function school(): BelongsTo
    {
        return $this->belongsTo(School::class);
    }

    public function grantedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }
}
