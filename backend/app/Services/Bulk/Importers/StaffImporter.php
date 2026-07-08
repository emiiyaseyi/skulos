<?php

namespace App\Services\Bulk\Importers;

use App\Models\Tenant\Staff;
use App\Services\Bulk\Importer;
use Illuminate\Validation\Rule;

/**
 * Bulk import of staff from a CSV matching the downloadable template.
 * Staff ID is the unique key; existing IDs are rejected.
 */
class StaffImporter extends Importer
{
    public function columns(): array
    {
        return [
            'staff_id' => 'Staff ID',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'other_names' => 'Other Names',
            'gender' => 'Gender',
            'email' => 'Email',
            'phone' => 'Phone',
            'position' => 'Position',
        ];
    }

    protected function rules(array $row): array
    {
        return [
            'staff_id' => [
                'required', 'string', 'max:100',
                Rule::unique((new Staff)->getConnectionName().'.staff', 'staff_id'),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:20'],
            'email' => ['nullable', 'email', 'max:150'],
            'phone' => ['nullable', 'string', 'max:40'],
            'position' => ['nullable', 'string', 'max:100'],
        ];
    }

    protected function uniqueBy(): array
    {
        return ['staff_id'];
    }

    protected function persist(array $row): void
    {
        Staff::create([
            'staff_id' => $row['staff_id'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'other_names' => $row['other_names'] ?? null,
            'gender' => $row['gender'] ?? null,
            'email' => $row['email'] ?? null,
            'phone' => $row['phone'] ?? null,
            'position' => $row['position'] ?? null,
        ]);
    }

    protected function auditName(): string
    {
        return 'staff';
    }
}
