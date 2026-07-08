<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Links a student to a guardian. guardian_user_id references a platform
 * User (the parent account, unique by email at platform level) once the
 * guardian has an account; before that the contact fields stand alone.
 */
class StudentGuardian extends Model
{
    use Auditable;

    protected $connection = 'tenant';

    protected $fillable = [
        'student_id', 'name', 'relationship', 'email', 'phone',
        'guardian_user_id', 'is_primary',
    ];

    protected function casts(): array
    {
        return ['is_primary' => 'boolean'];
    }

    public function student(): BelongsTo
    {
        return $this->belongsTo(Student::class);
    }
}
