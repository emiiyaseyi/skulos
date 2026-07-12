<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A staff record, keyed externally by staff_id. Lives in the tenant
 * database. `position` describes the job; permissions come from the RBAC
 * role, not this field.
 */
class Staff extends Model
{
    use Auditable;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_ON_LEAVE = 'on_leave';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_RESIGNED = 'resigned';

    protected $connection = 'tenant';

    protected $table = 'staff';

    protected $fillable = [
        'staff_id', 'first_name', 'last_name', 'other_names', 'gender',
        'email', 'phone', 'position', 'date_of_birth', 'employed_on',
        'qualification', 'address', 'passport_path', 'custom', 'status', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'employed_on' => 'date',
            'custom' => 'array',
        ];
    }

    public function teachingAssignments(): HasMany
    {
        return $this->hasMany(TeachingAssignment::class);
    }

    /**
     * The platform login this staff member signs in with, if one has been
     * created for them. Built manually rather than a plain belongsTo():
     * Eloquent's Model::newRelatedInstance() silently inherits the PARENT
     * model's connection ("tenant") onto the related model whenever the
     * related model (User) has no $connection of its own, so a bare
     * belongsTo(User::class) tries to select from "users" on the tenant
     * SQLite file and throws "no such table: users" (found live 2026-07-12
     * while smoke testing the new Staff Portal screen). Explicitly pinning
     * the related instance to the platform's own default connection before
     * handing it to newBelongsTo() sidesteps that inheritance entirely.
     */
    public function user(): BelongsTo
    {
        $related = (new User())->setConnection(config('database.default'));

        return $this->newBelongsTo($related->newQuery(), $this, 'user_id', 'id', 'user');
    }

    public function fullName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->other_names, $this->last_name]));
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
