<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;

/**
 * An admin-defined field (user request 2026-07-22), applied to one or more
 * of Student/Staff/Guardian. See the matching migration's docblock for why
 * this is EAV rather than a real column: a genuinely new field cannot mean
 * a schema migration per school, per field.
 */
class CustomFieldDefinition extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    public const TYPES = ['text', 'textarea', 'number', 'date', 'select', 'checkbox'];

    public const ENTITY_TYPES = ['student', 'staff', 'guardian'];

    protected $fillable = ['label', 'field_key', 'type', 'options', 'entity_types', 'is_required', 'sequence', 'is_active'];

    protected function casts(): array
    {
        return [
            'options' => 'array',
            'entity_types' => 'array',
            'is_required' => 'boolean',
            'sequence' => 'integer',
            'is_active' => 'boolean',
        ];
    }

    public function values(): HasMany
    {
        return $this->hasMany(CustomFieldValue::class);
    }

    public function appliesTo(string $entityType): bool
    {
        return in_array($entityType, $this->entity_types ?? [], true);
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
