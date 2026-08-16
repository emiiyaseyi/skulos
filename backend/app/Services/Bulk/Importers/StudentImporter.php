<?php

namespace App\Services\Bulk\Importers;

use App\Models\Tenant\ClassArm;
use App\Models\Tenant\House;
use App\Models\Tenant\Setting;
use App\Models\Tenant\Student;
use App\Models\Tenant\StudentGuardian;
use App\Services\Bulk\Importer;
use Illuminate\Validation\Rule;

/**
 * Bulk import of students from a CSV that matches the downloadable
 * template (columns() below). Admission number is the unique key; email
 * is optional. Existing admission numbers are rejected so an import never
 * silently overwrites a student.
 *
 * "class" and "house" are matched by name against the school's own
 * ClassArm labels / House names (case-insensitive, trimmed), not stored
 * as free text. Which optional fields are required is admin configurable
 * per school (user request 2026-07-10, config('skulos.student_optional_fields'),
 * stored in tenant Setting under "student_required_fields") — admission
 * number/first/last name are always required and email is never required,
 * neither is affected by that setting.
 */
class StudentImporter extends Importer
{
    protected array $requiredFields;

    protected array $classArmsByLabel = [];

    protected array $housesByName = [];

    public function __construct()
    {
        $this->requiredFields = Setting::grab('student_required_fields', []);

        foreach (ClassArm::with(['schoolClass', 'arm'])->get() as $classArm) {
            $this->classArmsByLabel[mb_strtolower(trim($classArm->label()))] = $classArm->id;
        }
        foreach (House::all() as $house) {
            $this->housesByName[mb_strtolower(trim($house->name))] = $house->id;
        }
    }

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
            // Real guardian columns (2026-08-16): the sheet only ever
            // carried a bare mobile number, which created no guardian
            // record at all -- so an imported school had students with
            // nobody attached, and every parent had to be added again by
            // hand afterwards.
            'parent1_name' => 'Parent/Guardian 1 Name',
            'parent1_email' => 'Parent/Guardian 1 Email',
            'parent1_phone' => 'Parent/Guardian 1 Phone',
            'parent1_relationship' => 'Parent/Guardian 1 Relationship',
            'parent2_name' => 'Parent/Guardian 2 Name',
            'parent2_email' => 'Parent/Guardian 2 Email',
            'parent2_phone' => 'Parent/Guardian 2 Phone',
            'parent2_relationship' => 'Parent/Guardian 2 Relationship',
            'class' => 'Class',
            'house' => 'House',
            'state_of_origin' => 'State of Origin',
            'lga' => 'LGA',
            'address' => 'Address',
        ];
    }

    protected function requiredOrNullable(string $field): string
    {
        return in_array($field, $this->requiredFields, true) ? 'required' : 'nullable';
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
            'other_names' => [$this->requiredOrNullable('other_names'), 'string', 'max:100'],
            'gender' => [$this->requiredOrNullable('gender'), 'string', 'max:20'],
            'date_of_birth' => [$this->requiredOrNullable('date_of_birth'), 'date'],
            'email' => ['nullable', 'email', 'max:150'], // optional, never required
            'parent_mobile' => [$this->requiredOrNullable('parent_mobile'), 'string', 'max:40'],
            'class' => [
                $this->requiredOrNullable('class'),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value !== null && $value !== '' && ! isset($this->classArmsByLabel[mb_strtolower(trim($value))])) {
                        $fail("Class \"{$value}\" was not found. Check the exact name under Classes & Subjects.");
                    }
                },
            ],
            'house' => [
                $this->requiredOrNullable('house'),
                function (string $attribute, mixed $value, \Closure $fail) {
                    if ($value !== null && $value !== '' && ! isset($this->housesByName[mb_strtolower(trim($value))])) {
                        $fail("House \"{$value}\" was not found. Check the exact name under Houses.");
                    }
                },
            ],
            'parent1_name' => [$this->requiredOrNullable('parent1_name'), 'string', 'max:150'],
            'parent1_email' => ['nullable', 'email', 'max:150'],
            'parent1_phone' => ['nullable', 'string', 'max:40'],
            'parent1_relationship' => ['nullable', 'string', 'max:40'],
            'parent2_name' => ['nullable', 'string', 'max:150'],
            'parent2_email' => ['nullable', 'email', 'max:150'],
            'parent2_phone' => ['nullable', 'string', 'max:40'],
            'parent2_relationship' => ['nullable', 'string', 'max:40'],
            'state_of_origin' => [$this->requiredOrNullable('state_of_origin'), 'string', 'max:100'],
            'lga' => [$this->requiredOrNullable('lga'), 'string', 'max:100'],
            'address' => [$this->requiredOrNullable('address'), 'string', 'max:500'],
        ];
    }

    protected function uniqueBy(): array
    {
        return ['admission_number'];
    }

    /** Powers the preview's "already on record" flag. */
    protected function existsAlready(string $column, string $value): bool
    {
        // withTrashed on purpose: a withdrawn student still owns their
        // admission number, so re-importing it would collide at the
        // database level even though nothing is visible on screen.
        return Student::withTrashed()->where($column, $value)->exists();
    }

    protected function persist(array $row): void
    {
        $student = Student::create([
            'admission_number' => $row['admission_number'],
            'first_name' => $row['first_name'],
            'last_name' => $row['last_name'],
            'other_names' => $row['other_names'] ?? null,
            'gender' => $row['gender'] ?? null,
            'date_of_birth' => $row['date_of_birth'] ?? null,
            'email' => $row['email'] ?? null,
            'parent_mobile' => $row['parent_mobile'] ?? null,
            'current_class_arm_id' => $this->resolve($this->classArmsByLabel, $row['class'] ?? null),
            'house_id' => $this->resolve($this->housesByName, $row['house'] ?? null),
            'state_of_origin' => $row['state_of_origin'] ?? null,
            'lga' => $row['lga'] ?? null,
            'address' => $row['address'] ?? null,
        ]);

        // A guardian row per parent given. Written here rather than left
        // to a later manual step, because a student imported with no
        // guardian has nobody who can sign in to see them, which is the
        // main thing a parent account is for.
        foreach (['parent1', 'parent2'] as $index => $prefix) {
            $name = trim((string) ($row[$prefix.'_name'] ?? ''));
            $phone = trim((string) ($row[$prefix.'_phone'] ?? ''));
            $email = trim((string) ($row[$prefix.'_email'] ?? ''));

            // The legacy single "parent_mobile" column still counts as
            // the first guardian's phone when no explicit one is given,
            // so an older sheet keeps working exactly as it did.
            if ($prefix === 'parent1' && $phone === '') {
                $phone = trim((string) ($row['parent_mobile'] ?? ''));
            }

            if ($name === '' && $phone === '' && $email === '') {
                continue;
            }

            StudentGuardian::create([
                'student_id' => $student->id,
                'name' => $name !== '' ? $name : 'Parent/Guardian',
                'relationship' => $row[$prefix.'_relationship'] ?? null,
                'email' => $email !== '' ? $email : null,
                'phone' => $phone !== '' ? $phone : null,
                // The first one given is the primary contact, which is
                // who notifications and fee reminders go to.
                'is_primary' => $index === 0,
            ]);
        }
    }

    protected function resolve(array $lookup, ?string $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return $lookup[mb_strtolower(trim($value))] ?? null;
    }

    protected function auditName(): string
    {
        return 'students';
    }
}
