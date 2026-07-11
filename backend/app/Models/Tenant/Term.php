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

    /**
     * Also makes this term's own session current (found 2026-07-11: making
     * a term current without this could leave the "current term" belonging
     * to a session that itself was not flagged current, an inconsistent
     * state anything reading both scopes together could disagree on).
     *
     * The target row is set via a direct query update, not
     * forceFill()->save(): see AcademicSession::makeCurrent()'s docblock
     * for why, the same stale-in-memory-attribute bug applies here too.
     */
    public function makeCurrent(): void
    {
        static::query()->where('is_current', true)->update(['is_current' => false]);
        static::query()->whereKey($this->getKey())->update(['is_current' => true]);
        $this->is_current = true;
        $this->session?->makeCurrent();
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }
}
