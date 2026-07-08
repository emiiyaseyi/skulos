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

    protected $fillable = ['name', 'starts_on', 'ends_on', 'is_current'];

    protected function casts(): array
    {
        return [
            'starts_on' => 'date',
            'ends_on' => 'date',
            'is_current' => 'boolean',
        ];
    }

    public function terms(): HasMany
    {
        return $this->hasMany(Term::class);
    }

    /**
     * Make this the only current session (a school has one active session).
     */
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
