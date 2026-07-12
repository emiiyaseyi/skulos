<?php

namespace App\Services\Academics;

use App\Models\Tenant\ClassArm;
use App\Models\Tenant\GradingBand;
use App\Models\Tenant\GradingScale;
use App\Models\Tenant\Section;
use App\Models\Tenant\SchoolClass;
use App\Models\Tenant\Subject;

/**
 * One-click starting points for a school's subject list and grading
 * scales (user request 2026-07-09, sourced from the school's own
 * "List of subjects and Grading system.docx"). Every action here is
 * opt-in and idempotent: nothing is seeded automatically on tenant
 * provisioning, so it can never collide with a school's own data or with
 * tests that create their own subjects/scales. The admin can edit, add
 * to, or remove anything created this way afterwards.
 */
class AcademicDefaults
{
    /**
     * Suggest a default-subjects catalog key for a section, matching its
     * name against the configured keywords. Returns null if nothing
     * matches so the caller can let the admin pick manually.
     */
    public function suggestCatalogFor(Section $section): ?string
    {
        $name = strtolower($section->name);

        foreach (config('skulos.default_subjects', []) as $key => $catalog) {
            foreach ($catalog['match'] ?? [] as $needle) {
                if (str_contains($name, $needle)) {
                    return $key;
                }
            }
        }

        return null;
    }

    /**
     * Create (firstOrCreate by name) and attach every subject in the given
     * catalog to the class, with its mandatory/examinable flags. Subjects
     * already attached to the class are left untouched. Returns how many
     * were newly attached.
     *
     * Each subject's sequence is set to the catalog's declared array
     * order, offset by the catalog's "sequence_base" (found live
     * 2026-07-12: subject lists were rendering alphabetically, not in
     * real curriculum order). Only ever fills in a still-unset sequence
     * (0, the column default), never overwrites one already assigned —
     * so the retroactive repair pass (every existing real subject is
     * still at 0, this feature is new) fixes every school once, but an
     * admin's later manual reorder via updateSubject(), or a subject
     * shared by two catalogs, is never silently reset by a later catalog
     * application.
     */
    public function applySubjectCatalog(SchoolClass $class, string $catalogKey): int
    {
        $catalog = config("skulos.default_subjects.{$catalogKey}");
        abort_unless($catalog, 404, 'Unknown subject catalog.');

        $already = $class->subjects()->pluck('subjects.id')->all();
        $applied = 0;
        $base = $catalog['sequence_base'] ?? 0;

        foreach ($catalog['subjects'] as $index => $entry) {
            $sequence = $base + $index + 1;
            $subject = Subject::firstOrCreate(['name' => $entry['name']], ['sequence' => $sequence]);
            if ($subject->sequence === 0) {
                $subject->update(['sequence' => $sequence]);
            }
            if (in_array($subject->id, $already, true)) {
                continue;
            }
            $class->subjects()->attach($subject->id, [
                'is_mandatory' => $entry['mandatory'],
                'is_examinable' => $entry['examinable'],
            ]);
            $applied++;
        }

        return $applied;
    }

    /**
     * Load one or more class structure tiers (user request 2026-07-10):
     * for each tier key, find-or-create its Section, find-or-create each
     * listed class under it (with a default single-stream ClassArm so
     * it is immediately usable), then apply that tier's default subject
     * catalog to every class. Idempotent, same as applySubjectCatalog:
     * re-running it after the admin has renamed/removed things only
     * fills in what is still missing, by name.
     *
     * Sequence numbers come from each tier's fixed "sequence"/"class_base"
     * in config, not from application order (found live 2026-07-12,
     * "Class Order & Structure.docx": a school ended up with Primary
     * listed above Nursery in every section-ordered list because Primary
     * happened to be ticked/loaded first — `Section::max('sequence')+1`
     * assigned sequence purely by whichever tier ran first, not the real
     * Nursery-then-Primary-then-Junior-then-Senior order). Both the
     * Section's sequence and every matched class's sequence are
     * corrected here on every call, not just on first creation, so
     * re-running this (or the retroactive repair pass) also fixes a
     * school whose sequence was already wrong before this fix landed.
     *
     * @param  list<string>  $catalogKeys
     * @return array{sections: int, classes: int, subjects: int}
     */
    public function applyClassStructure(array $catalogKeys): array
    {
        $sectionsTouched = 0;
        $classesCreated = 0;
        $subjectsApplied = 0;

        foreach ($catalogKeys as $key) {
            $tier = config("skulos.default_class_structure.{$key}");
            if (! $tier) {
                continue;
            }

            $section = Section::firstOrCreate(
                ['name' => $tier['section']],
                ['sequence' => $tier['sequence']]
            );
            if ($section->wasRecentlyCreated) {
                $sectionsTouched++;
            } elseif ($section->sequence !== $tier['sequence']) {
                $section->update(['sequence' => $tier['sequence']]);
            }

            foreach ($tier['classes'] as $index => $className) {
                $sequence = $tier['class_base'] + $index + 1;
                $class = SchoolClass::firstOrCreate(
                    ['section_id' => $section->id, 'name' => $className],
                    ['sequence' => $sequence]
                );
                if ($class->wasRecentlyCreated) {
                    $classesCreated++;
                    ClassArm::create(['school_class_id' => $class->id, 'arm_id' => null]);
                } elseif ($class->sequence !== $sequence) {
                    $class->update(['sequence' => $sequence]);
                }

                $subjectsApplied += $this->applySubjectCatalog($class, $key);
            }
        }

        return ['sections' => $sectionsTouched, 'classes' => $classesCreated, 'subjects' => $subjectsApplied];
    }

    /**
     * Retroactive repair only, never creates anything (found live
     * 2026-07-12 alongside the class-order bug: a real school's Section/
     * SchoolClass/Subject rows already existed with the old, wrong
     * sequence values from before this fix, and `applyClassStructure()`
     * cannot safely be re-run broadly to fix them, it would firstOrCreate
     * an entire tier a school never opted into, e.g. inventing a Senior
     * Secondary section and SSS 1 to 3 classes for a primary only
     * school). This instead only updates a Section/SchoolClass/Subject
     * that already exists and whose name matches the canonical catalog
     * exactly, by the same sequence rules `applyClassStructure()` uses,
     * so a school's own custom sections/classes/subjects (that do not
     * match a catalog name) are left completely untouched, same as
     * always.
     *
     * @return array{sections: int, classes: int, subjects: int}
     */
    public function realignSequences(): array
    {
        $sectionsFixed = 0;
        $classesFixed = 0;
        $subjectsFixed = 0;

        foreach (config('skulos.default_class_structure', []) as $key => $tier) {
            $section = Section::where('name', $tier['section'])->first();
            if (! $section) {
                continue;
            }
            if ($section->sequence !== $tier['sequence']) {
                $section->update(['sequence' => $tier['sequence']]);
                $sectionsFixed++;
            }

            foreach ($tier['classes'] as $index => $className) {
                $class = SchoolClass::where('section_id', $section->id)->where('name', $className)->first();
                if (! $class) {
                    continue;
                }
                $sequence = $tier['class_base'] + $index + 1;
                if ($class->sequence !== $sequence) {
                    $class->update(['sequence' => $sequence]);
                    $classesFixed++;
                }
            }
        }

        foreach (config('skulos.default_subjects', []) as $catalog) {
            $base = $catalog['sequence_base'] ?? 0;
            foreach ($catalog['subjects'] as $index => $entry) {
                $subject = Subject::where('name', $entry['name'])->first();
                if (! $subject || $subject->sequence !== 0) {
                    continue;
                }
                $subject->update(['sequence' => $base + $index + 1]);
                $subjectsFixed++;
            }
        }

        return ['sections' => $sectionsFixed, 'classes' => $classesFixed, 'subjects' => $subjectsFixed];
    }

    /**
     * Create a default grading scale (+ its bands) if a scale of that name
     * does not already exist. Returns the existing or newly created scale.
     */
    public function applyGradingScale(string $catalogKey): GradingScale
    {
        $catalog = config("skulos.default_grading_scales.{$catalogKey}");
        abort_unless($catalog, 404, 'Unknown grading scale catalog.');

        $existing = GradingScale::where('name', $catalog['name'])->first();
        if ($existing) {
            return $existing;
        }

        $scale = GradingScale::create(['name' => $catalog['name']]);
        foreach ($catalog['bands'] as $band) {
            GradingBand::create([
                'grading_scale_id' => $scale->id,
                'min_score' => $band['min'],
                'max_score' => $band['max'],
                'grade' => $band['grade'],
                'remark' => $band['remark'],
            ]);
        }

        return $scale;
    }
}
