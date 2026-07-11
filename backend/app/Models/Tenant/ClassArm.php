<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

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

    protected $fillable = ['school_class_id', 'arm_id', 'class_teacher_staff_id', 'capacity'];

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
