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
        'next_term_begins_on',
        'is_current',
        'total_school_days',
    ];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'starts_on' => 'date',
            'ends_on' => 'date',
            'next_term_begins_on' => 'date',
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

    /**
     * A sortByDesc() key that puts the most recent academic year first,
     * then within that year the most recent term first (e.g. Third,
     * Second, First). Real, chronological year, not this term's own
     * session's row id (found live on greenfield, user report
     * 2026-07-28: "why are all the term not organized serially" — a
     * session created out of order, e.g. a historical 2024/2025 session
     * added AFTER the 2025/2026 one already existed, gets a HIGHER id
     * despite being an EARLIER year, so `academic_session_id DESC`
     * silently sorted years out of order). AcademicSession.starts_on
     * would be the obviously correct field, but is not reliably
     * populated (a newly created future session can have it blank
     * before an admin sets real dates), so the year is instead parsed
     * from the session's own name, which this app's AcademicSession
     * seeder/admin form always starts with a 4 digit year
     * ("2026/2027 Session"): (int) on a string stops at the first non
     * digit character, so this is robust without needing a strict
     * format assumption beyond "starts with a year".
     */
    public function chronologicalSortKey(): string
    {
        return sprintf('%05d-%05d', (int) $this->session?->name, $this->sequence ?? 0);
    }
}
