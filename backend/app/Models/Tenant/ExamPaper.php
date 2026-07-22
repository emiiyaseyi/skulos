<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row of the school's exam-day structure, e.g. "Paper 1" 8:30-10:30
 * or a non-assignable "Break" row — see the matching migration's
 * docblock for why this is duration-driven and not independently timed
 * per row the way TimetablePeriod already is.
 */
class ExamPaper extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['name', 'sequence', 'duration_minutes', 'is_break', 'starts_at', 'ends_at'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'duration_minutes' => 'integer',
            'is_break' => 'boolean',
        ];
    }

    public function timeLabel(): string
    {
        return $this->starts_at && $this->ends_at
            ? substr((string) $this->starts_at, 0, 5).' to '.substr((string) $this->ends_at, 0, 5)
            : 'Not set';
    }
}
