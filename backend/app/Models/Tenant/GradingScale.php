<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * A configurable grading scale, e.g. "Secondary Scale". Bands map score
 * ranges to grades. A school can define any number of scales.
 */
class GradingScale extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'is_default', 'sequence'];

    protected function casts(): array
    {
        return ['is_default' => 'boolean', 'sequence' => 'integer'];
    }

    /** Highest grade first (A before F), same order the config catalog already declares each scale's bands in. */
    public function bands(): HasMany
    {
        return $this->hasMany(GradingBand::class)->orderByDesc('min_score');
    }

    public function makeDefault(): void
    {
        static::query()->where('is_default', true)->update(['is_default' => false]);
        $this->forceFill(['is_default' => true])->save();
    }

    public static function default(): ?self
    {
        return static::where('is_default', true)->with('bands')->first();
    }

    /**
     * Find the band a score falls into.
     */
    public function bandFor(float $score): ?GradingBand
    {
        return $this->bands
            ->first(fn (GradingBand $b) => $score >= (float) $b->min_score && $score <= (float) $b->max_score);
    }
}
