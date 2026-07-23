<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;

/**
 * The enrollable unit, e.g. "Basic 1 A". A class with no arms has a single
 * ClassArm with arm_id null. class_teacher_staff_id references a Staff row
 * (set once staff exist); it is a plain id, no FK, so a class arm can be
 * created before its teacher.
 */
class ClassArm extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['school_class_id', 'arm_id', 'class_teacher_staff_id', 'assistant_teacher_staff_id', 'capacity', 'stamp_path', 'signature_path'];

    protected function casts(): array
    {
        return ['capacity' => 'integer'];
    }

    public function schoolClass(): BelongsTo
    {
        return $this->belongsTo(SchoolClass::class);
    }

    public function arm(): BelongsTo
    {
        return $this->belongsTo(Arm::class);
    }

    public function classTeacher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'class_teacher_staff_id');
    }

    public function assistantTeacher(): BelongsTo
    {
        return $this->belongsTo(Staff::class, 'assistant_teacher_staff_id');
    }

    /**
     * This stream's own subject overrides, if any (2026-07-23, per stream
     * subject offerings for SSS's Arts/Science/Commercial streams). Rarely
     * queried directly, see offeredSubjects() below for the resolver every
     * caller should actually use.
     */
    public function subjects(): BelongsToMany
    {
        return $this->belongsToMany(Subject::class, 'class_arm_subjects')
            ->withPivot(['is_mandatory', 'is_examinable'])
            ->withTimestamps()
            ->orderBy('subjects.sequence')
            ->orderBy('subjects.name');
    }

    /**
     * The subjects a student in this specific class arm actually studies:
     * the whole class's own shared list (its mandatory subjects, Maths/
     * English/Civic for an SSS class) PLUS this arm's own additional
     * subjects if it has any (an SSS stream's Science/Arts/Commercial
     * electives) — additive, not a replacement, since a stream's students
     * still take the class's mandatory subjects on top of their stream's
     * own. A class arm with no overrides of its own (every class in
     * practice besides SSS's 3 streams) is completely unaffected: nothing
     * to add on top of the class's existing shared list, exactly what it
     * already returned before per stream overrides existed.
     */
    public function offeredSubjects(): \Illuminate\Support\Collection
    {
        $classSubjects = $this->schoolClass?->subjects ?? collect();
        $ownSubjects = $this->subjects;

        return $classSubjects->concat($ownSubjects)->unique('id')
            ->sortBy([['sequence', 'asc'], ['name', 'asc']])->values();
    }

    /**
     * Human label, e.g. "Basic 1 A" (or just "Basic 1" for a single stream).
     */
    public function label(): string
    {
        $class = $this->schoolClass?->name ?? '';
        $arm = $this->arm?->name;

        return $arm ? trim("{$class} {$arm}") : $class;
    }

    /**
     * The school's real class order, Nursery through Secondary in
     * sequence, not the label's alphabetical order (found 2026-07-11:
     * every class picker sorting by `label()` put "JSS 3" before
     * "Nursery 1" before "Primary 1", not the pedagogical order a school
     * actually uses). Load `schoolClass.section` before sorting a
     * collection by this, sorting triggers a query per row otherwise.
     */
    public function orderKey(): string
    {
        $sectionSeq = $this->schoolClass?->section?->sequence ?? 0;
        $classSeq = $this->schoolClass?->sequence ?? 0;

        return sprintf('%05d-%05d-%s', $sectionSeq, $classSeq, $this->label());
    }
}
