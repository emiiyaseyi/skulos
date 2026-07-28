<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;

/**
 * One row/slot on the school's timetable grid, e.g. "Period 1" 8:00-8:40,
 * or a non teaching "Break"/"Lunch" row shown on the grid but never
 * assignable to a subject. A school's own catalog, seeded once from
 * config('skulos.default_timetable_periods') then fully admin editable,
 * same seed-then-DB-table shape as grading_scales/comments.
 *
 * `section_id` is nullable (2026-07-28, "section-specific default
 * timetable period durations"): NULL is the shared/default catalog every
 * section falls back to; a section with any period rows of its own
 * (section_id = that section) uses ONLY those, a full independent
 * catalog, not a merge on top of the shared one — the same "own override
 * fully replaces the fallback" shape already used for
 * timetable_entries.term_id.
 */
class TimetablePeriod extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['section_id', 'name', 'sequence', 'starts_at', 'ends_at', 'is_teaching_period'];

    protected function casts(): array
    {
        return [
            'sequence' => 'integer',
            'is_teaching_period' => 'boolean',
        ];
    }

    public function section()
    {
        return $this->belongsTo(Section::class);
    }

    public function entries()
    {
        return $this->hasMany(TimetableEntry::class);
    }

    public function timeLabel(): string
    {
        return substr((string) $this->starts_at, 0, 5).' to '.substr((string) $this->ends_at, 0, 5);
    }
}
