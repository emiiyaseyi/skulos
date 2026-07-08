<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * A term within a session, e.g. "First Term". `sequence` lets a school run
 * two, three, or any number of terms, so nothing about term count is
 * hardcoded (annual pricing = 3x termly is a billing rule, not a schema one).
 */
class Term extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'academic_session_id',
        'name',
        'sequence',
        'starts_on',
        'ends_on',
        'is_current',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function session(): BelongsTo
    {
        return $this->belongsTo(AcademicSession::class, 'academic_session_id');
    }

    public function makeCurrent(): void
    {
        static::query()->where('is_current', true)->update(['is_current' => false]);
        $this->forceFill(['is_current' => true])->save();
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }
}
