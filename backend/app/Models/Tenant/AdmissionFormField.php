<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * One admin-defined field on the school's custom application form. See
 * the creating migration's docblock for the CustomFieldDefinition-shaped
 * pattern this adapts and what is deliberately out of scope.
 */
class AdmissionFormField extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];

    protected $fillable = ['admission_form_section_id', 'label', 'field_key', 'type', 'options', 'is_required', 'sequence', 'is_active'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'is_required' => 'boolean',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function section(): BelongsTo
    {
        return $this->belongsTo(AdmissionFormSection::class, 'admission_form_section_id');
    }

    public function values(): HasMany
    {
        return $this->hasMany(AdmissionFormValue::class);
    }

    /** A stable, unique slug derived from the label, used as the form field name and value lookup key. */
    public static function keyFromLabel(string $label): string
    {
        $base = Str::slug($label, '_') ?: 'field';
        $key = $base;
        $suffix = 1;

        while (static::where('field_key', $key)->exists()) {
            $key = $base.'_'.(++$suffix);
        }

        return $key;
    }
}
