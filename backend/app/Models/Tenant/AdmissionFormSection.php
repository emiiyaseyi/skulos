<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * One group of fields on the school's custom application form, shown
 * together on one step of the multi-step wizard.
 */
class AdmissionFormSection extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = ['label', 'step', 'sequence', 'is_active'];

    protected function casts(): array
    {
        return ['step' => 'integer', 'sequence' => 'integer', 'is_active' => 'boolean'];
    }

    public function fields(): HasMany
    {
        return $this->hasMany(AdmissionFormField::class);
    }

    /**
     * Seeds config('skulos.default_admission_form_sections') once, so a
     * freshly provisioned school (or one that never touched this screen
     * yet) has a real starter form instead of a blank one (user
     * directive 2026-07-31: "the form builder should be pre loaded with
     * current form currently used by all schools"). A no-op the moment
     * any section already exists, including one an admin later deletes
     * back down to zero — this only ever fires once per school, never
     * resurrects a deliberately emptied form.
     */
    public static function ensureSeeded(): void
    {
        if (Setting::grab('admission_form_seeded') || static::query()->exists()) {
            return;
        }

        foreach (config('skulos.default_admission_form_sections', []) as $sectionSeq => $sectionData) {
            $section = static::create([
                'label' => $sectionData['label'],
                'step' => $sectionData['step'],
                'sequence' => $sectionSeq + 1,
            ]);

            foreach ($sectionData['fields'] as $fieldSeq => $fieldData) {
                AdmissionFormField::create([
                    'admission_form_section_id' => $section->id,
                    'label' => $fieldData['label'],
                    'field_key' => AdmissionFormField::keyFromLabel($fieldData['label']),
                    'type' => $fieldData['type'],
                    'options' => $fieldData['options'] ?? null,
                    'is_required' => false,
                    'sequence' => $fieldSeq + 1,
                ]);
            }
        }

        Setting::put('admission_form_seeded', true);
    }
}
