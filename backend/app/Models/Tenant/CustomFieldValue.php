<?php

namespace App\Models\Tenant;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * One admin-defined field's value against one specific student/staff/
 * guardian record. entity_id is a plain id, not a real foreign key: which
 * table it points into depends on entity_type (see the migration's
 * docblock for why this stays a simple two-column pair rather than a full
 * polymorphic morph setup).
 */
class CustomFieldValue extends Model
{
    protected $connection = 'tenant';

    protected $fillable = ['custom_field_definition_id', 'entity_type', 'entity_id', 'value'];

    public function definition(): BelongsTo
    {
        return $this->belongsTo(CustomFieldDefinition::class, 'custom_field_definition_id');
    }
}
