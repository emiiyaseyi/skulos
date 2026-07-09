<?php

namespace App\Services\Academics;

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
     */
    public function applySubjectCatalog(SchoolClass $class, string $catalogKey): int
    {
        $catalog = config("skulos.default_subjects.{$catalogKey}");
        abort_unless($catalog, 404, 'Unknown subject catalog.');

        $already = $class->subjects()->pluck('subjects.id')->all();
        $applied = 0;

        foreach ($catalog['subjects'] as $entry) {
            $subject = Subject::firstOrCreate(['name' => $entry['name']]);
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
