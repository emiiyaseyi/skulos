<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use App\Models\User;
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

    /**
     * The platform login this guardian signs in with, if one has been
     * created for them. Built manually, not a plain belongsTo() — see the
     * matching comment on App\Models\Tenant\Staff::user(), the same real
     * bug (Eloquent inherits the tenant connection onto User, a platform
     * model, causing "no such table: users") applies here identically.
     */
    public function user(): BelongsTo
    {
        $related = (new User())->setConnection(config('database.default'));

        return $this->newBelongsTo($related->newQuery(), $this, 'guardian_user_id', 'id', 'user');
    }
}
