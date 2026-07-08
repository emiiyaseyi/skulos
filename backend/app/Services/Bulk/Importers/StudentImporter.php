<?php

namespace App\Services\Bulk\Importers;

use App\Models\Tenant\Student;
use App\Services\Bulk\Importer;
use Illuminate\Validation\Rule;

/**
 * Bulk import of students from a CSV that matches the downloadable
 * template (columns() below). Admission number is the unique key; email
 * is optional. Existing admission numbers are rejected so an import never
 * silently overwrites a student.
 */
class StudentImporter extends Importer
{
    public function columns(): array
    {
        return [
            'admission_number' => 'Admission Number',
            'first_name' => 'First Name',
            'last_name' => 'Last Name',
            'other_names' => 'Other Names',
            'gender' => 'Gender',
            'date_of_birth' => 'Date of Birth (YYYY-MM-DD)',
            'email' => 'Email (optional)',
            'parent_mobile' => 'Parent Mobile',
        ];
    }

    protected function rules(array $row): array
    {
        return [
            'admission_number' => [
                'required', 'string', 'max:100',
                Rule::unique((new Student)->getConnectionName().'.students', 'admission_number'),
            ],
            'first_name' => ['required', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'other_names' => ['nullable', 'string', 'max:100'],
            'gender' => ['nullable', 'string', 'max:20'],
            'date_of_birth' => ['nullable', 'date'],
            'email' => ['nullable', 'email', 'max:150'], // optional, never required
            'parent_mobile' => ['nullable', 'string', 'max:40'],
        ];
    }

    protected function uniqueBy(): array
    {
        return ['admission_number'];
    }

    protected function persist(array $row): void
    {
        Student::create([
            'admission_number' => $row['admission_number'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'other_names' => $row['other_names'] ?? null,
            'gender' => $row['gender'] ?? null,
            'date_of_birth' => $row['date_of_birth'] ?? null,
            'email' => $row['email'] ?? null,
            'parent_mobile' => $row['parent_mobile'] ?? null,
        ]);
    }

    protected function auditName(): string
    {
        return 'students';
    }
}
