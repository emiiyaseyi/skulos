<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
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

    public function fullName(): string
    {
        return implode(' ', array_filter([$this->first_name, $this->other_names, $this->last_name]));
    }

    public function scopeActive($query)
    {
        return $query->where('status', self::STATUS_ACTIVE);
    }
}
