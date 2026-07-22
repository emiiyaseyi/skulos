<?php

namespace App\Services\CustomFields;

use App\Models\Tenant\CustomFieldDefinition;
use App\Models\Tenant\CustomFieldValue;
use Illuminate\Support\Collection;

/**
 * Reads/validates/saves admin-defined custom fields (user request
 * 2026-07-22) against a student, staff, or guardian record. Form inputs
 * for these fields are always submitted nested under "custom[{field_key}]"
 * so they can never collide with a real column name on any of the three
 * forms this is wired into.
 */
class CustomFieldService
{
    /** Active definitions that apply to this entity type, in display order. */
    public function forEntity(string $entityType): Collection
    {
        return CustomFieldDefinition::where('is_active', true)
            ->get()
            ->filter(fn (CustomFieldDefinition $def) => $def->appliesTo($entityType))
            ->sortBy('sequence')
            ->values();
    }

    /** @return array<string, string|null> field_key => current value, for a specific entity instance. */
    public function valuesFor(string $entityType, int $entityId): array
    {
        return CustomFieldValue::where('entity_type', $entityType)
            ->where('entity_id', $entityId)
            ->with('definition')
            ->get()
            ->filter(fn (CustomFieldValue $v) => $v->definition !== null)
            ->keyBy(fn (CustomFieldValue $v) => $v->definition->field_key)
            ->map(fn (CustomFieldValue $v) => $v->value)
            ->all();
    }

    /** @return array<string, array<int, string>> validation rules keyed by "custom.{field_key}" */
    public function validationRules(string $entityType): array
    {
        $rules = [];

        foreach ($this->forEntity($entityType) as $definition) {
            $required = $definition->is_required ? 'required' : 'nullable';
            $key = "custom.{$definition->field_key}";

            $rules[$key] = match ($definition->type) {
                'number' => [$required, 'numeric'],
                'date' => [$required, 'date'],
                'checkbox' => ['nullable', 'boolean'],
                'select' => [$required, 'string', 'in:'.implode(',', $definition->options ?? [])],
                default => [$required, 'string', 'max:2000'],
            };
        }

        return $rules;
    }

    /** Upserts one CustomFieldValue row per active definition for this entity instance. */
    public function saveValues(string $entityType, int $entityId, array $input): void
    {
        foreach ($this->forEntity($entityType) as $definition) {
            $value = $input[$definition->field_key] ?? null;

            if ($definition->type === 'checkbox') {
                $value = $value ? '1' : '0';
            }

            CustomFieldValue::updateOrCreate(
                ['custom_field_definition_id' => $definition->id, 'entity_type' => $entityType, 'entity_id' => $entityId],
                ['value' => $value === '' ? null : $value]
            );
        }
    }
}
