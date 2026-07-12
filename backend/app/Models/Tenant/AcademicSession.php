<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * An academic year, e.g. "2025/2026". Lives in the school's own database.
 */
class AcademicSession extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'starts_on', 'ends_on', 'is_current', 'is_archived'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
            'is_archived' => 'boolean',
        ];
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    /**
     * Make this the only current session (a school has one active session).
     * The target row is set via a direct query update, not
     * forceFill()->save(): if this model instance was loaded while
     * already is_current = true (e.g. it was already current, or was
     * just fetched via a relation), the bulk "set all false" line above
     * does not touch this instance's in-memory original attributes, so
     * forceFill()->save() would see "true" being set to "true", find
     * nothing dirty, and silently skip the write, even though the row
     * had just been flipped false by the bulk update a line earlier
     * (found 2026-07-11, a real bug: making an already-loaded session or
     * term "current" again could silently fail to persist).
     */
    public function makeCurrent(): void
    {
        static::query()->where('is_current', true)->update(['is_current' => false]);
        static::query()->whereKey($this->getKey())->update(['is_current' => true]);
        $this->is_current = true;
    }

    public function scopeCurrent($query)
    {
        return $query->where('is_current', true);
    }

    /** Excludes archived sessions, the default view (Dbase recommendation: keep old sessions out of default/daily queries). */
    public function scopeActive($query)
    {
        return $query->where('is_archived', false);
    }

    public function archive(): void
    {
        if ($this->is_current) {
            throw new \RuntimeException('The current session cannot be archived.');
        }

        $this->update(['is_archived' => true]);
    }

    public function unarchive(): void
    {
        $this->update(['is_archived' => false]);
    }
}
