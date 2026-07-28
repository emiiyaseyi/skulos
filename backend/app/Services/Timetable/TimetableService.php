<?php

namespace App\Services\Timetable;

use App\Models\Tenant\ClassArm;
use App\Models\Tenant\Term;
use App\Models\Tenant\TimetableEntry;
use App\Models\Tenant\TimetablePeriod;
use Illuminate\Support\Collection;

/**
 * Timetable Management (Advance plan feature, built 2026-07-18). Reads
 * and writes the weekly grid; the one piece of real domain logic is
 * conflict detection, since the same teacher obviously cannot be in two
 * classes at once, and the same class cannot have two subjects in the
 * same slot (the second is already enforced by a DB unique constraint,
 * the first is not, since a class arm's own uniqueness is per class, not
 * per teacher, so it is checked here before every write).
 *
 * A real term switcher (user request 2026-07-28: "Timetable pages
 * should also have term switcher too") is layered on top of the same
 * table, not a schema-per-term duplicate: `timetable_entries.term_id`
 * is nullable, a NULL row is a universal entry applying to every term,
 * a non-null row is a term-specific override. Every read below merges
 * the two per cell (universal first, then the requested term's own
 * rows overwrite matching cells), so an already-provisioned school's
 * existing timetable (every row still NULL, untouched by this feature)
 * keeps rendering identically for every term until an admin explicitly
 * saves a term-specific override.
 */
class TimetableService
{
    public function periods(): Collection
    {
        return TimetablePeriod::orderBy('sequence')->get();
    }

    public function weekDays(): array
    {
        return config('skulos.school_week_days', []);
    }

    /**
     * Seed the school's period catalog from config('skulos.
     * default_timetable_periods'), skipping any name that already
     * exists (same idempotent, never-overwrite-a-customization shape
     * GradingController::applyDefaults() already uses). Returns how many
     * were actually created.
     */
    public function applyDefaultPeriods(): int
    {
        $existingNames = TimetablePeriod::pluck('name')->all();
        $created = 0;

        foreach (array_values(config('skulos.default_timetable_periods', [])) as $index => $period) {
            if (in_array($period['name'], $existingNames, true)) {
                continue;
            }

            TimetablePeriod::create([
                'name' => $period['name'],
                'sequence' => $index + 1,
                'starts_at' => $period['starts_at'],
                'ends_at' => $period['ends_at'],
                'is_teaching_period' => $period['is_teaching_period'],
            ]);
            $created++;
        }

        return $created;
    }

    /**
     * Every entry for one class arm, keyed "dayKey:periodId" for O(1)
     * lookup while rendering the grid. $term null means "the universal
     * grid only" (every entry that still has no term-specific override
     * anywhere, the original pre-2026-07-28 behaviour, and what an
     * admin sees when no term is selected); a given $term additionally
     * lays that term's own rows on top, so a cell only that term
     * overrides changes, every other cell still falls back to the
     * universal row.
     *
     * @return array<string, TimetableEntry>
     */
    public function gridForClassArm(int $classArmId, ?Term $term = null): array
    {
        return $this->mergedGrid(
            TimetableEntry::where('class_arm_id', $classArmId),
            'subject', 'staff'
        )($term);
    }

    /**
     * Every entry for one staff member across every class arm, same
     * keying, for "My Timetable".
     *
     * @return array<string, TimetableEntry>
     */
    public function gridForStaff(int $staffId, ?Term $term = null): array
    {
        return $this->mergedGrid(
            TimetableEntry::where('staff_id', $staffId),
            'subject', 'classArm.schoolClass', 'classArm.arm'
        )($term);
    }

    /**
     * Shared universal+term merge for gridForClassArm()/gridForStaff():
     * both start from a different base query, so the base is passed in
     * and this returns a closure that finishes the query for a given
     * term and performs the actual merge.
     */
    protected function mergedGrid(\Illuminate\Database\Eloquent\Builder $baseQuery, string ...$with): \Closure
    {
        return function (?Term $term) use ($baseQuery, $with): array {
            $key = fn (TimetableEntry $e) => $e->day_of_week.':'.$e->timetable_period_id;

            $universal = (clone $baseQuery)->whereNull('term_id')->with($with)->get()->keyBy($key);
            if (! $term) {
                return $universal->all();
            }

            $termSpecific = (clone $baseQuery)->where('term_id', $term->id)->with($with)->get()->keyBy($key);

            return $universal->merge($termSpecific)->all();
        };
    }

    /**
     * Save a whole class arm's week in one action (the standing "bulk
     * update, not one request per cell" convention). $cells is
     * day => period_id => ['subject_id' => ?, 'staff_id' => ?], a blank
     * subject_id deletes that slot's entry rather than creating an empty
     * one. Returns the list of conflicts skipped (a teacher already
     * booked elsewhere in that exact day+period), so the caller can
     * report them without failing the whole save.
     *
     * $term null saves/deletes the universal (term_id NULL) rows, the
     * original behaviour; a given $term scopes every write to that
     * term's own override rows only, never touching the universal ones
     * or any other term's.
     *
     * @return list<string> human readable conflict messages, one per skipped cell
     */
    public function saveClassArmWeek(ClassArm $classArm, array $cells, ?Term $term = null): array
    {
        $conflicts = [];
        $termId = $term?->id;

        foreach ($cells as $dayKey => $periods) {
            foreach ($periods as $periodId => $cell) {
                $subjectId = $cell['subject_id'] ?? null;
                $staffId = $cell['staff_id'] ?? null;

                if (! $subjectId) {
                    TimetableEntry::where('class_arm_id', $classArm->id)
                        ->where('day_of_week', $dayKey)
                        ->where('timetable_period_id', $periodId)
                        ->where('term_id', $termId)
                        ->delete();

                    continue;
                }

                if ($staffId && $this->staffBookedElsewhere($staffId, $dayKey, (int) $periodId, $classArm->id, $termId)) {
                    $conflicts[] = "{$dayKey} period {$periodId}: that teacher is already booked in another class at this time.";

                    continue;
                }

                TimetableEntry::updateOrCreate(
                    ['class_arm_id' => $classArm->id, 'day_of_week' => $dayKey, 'timetable_period_id' => $periodId, 'term_id' => $termId],
                    ['subject_id' => $subjectId, 'staff_id' => $staffId ?: null]
                );
            }
        }

        return $conflicts;
    }

    /**
     * Scoped to the same universal-plus-this-term rows the grid itself
     * merges (a conflict check against a completely unrelated term's
     * own schedule would be a false positive, a school genuinely can
     * restructure who teaches what between terms).
     */
    protected function staffBookedElsewhere(int $staffId, string $dayKey, int $periodId, int $excludingClassArmId, ?int $termId): bool
    {
        return TimetableEntry::where('staff_id', $staffId)
            ->where('day_of_week', $dayKey)
            ->where('timetable_period_id', $periodId)
            ->where('class_arm_id', '!=', $excludingClassArmId)
            ->where(fn ($q) => $q->whereNull('term_id')->when($termId, fn ($q2) => $q2->orWhere('term_id', $termId)))
            ->exists();
    }

    /**
     * Every class arm's own grid, all in one place (user request
     * 2026-07-21: "there should be a timetable like this showing for all
     * classes in the school so that the admin, school owner, head
     * teacher, assistant, principal and vice principals can see it at a
     * glance"). One entry per class arm rather than one flat table
     * (which would need a column per period per day, unreadable at any
     * real class count), the same "table per day, one row per class"
     * shape the school's own reference sample used.
     *
     * @return \Illuminate\Support\Collection<int, array{arm: ClassArm, grid: array<string, TimetableEntry>}>
     */
    public function masterGrid(?Term $term = null): Collection
    {
        return ClassArm::with('schoolClass.section', 'arm')->get()
            ->sortBy(fn (ClassArm $arm) => $arm->orderKey())
            ->values()
            ->map(fn (ClassArm $arm) => ['arm' => $arm, 'grid' => $this->gridForClassArm($arm->id, $term)]);
    }
}
