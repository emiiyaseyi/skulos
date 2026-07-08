<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant. Lives in the platform database; all of the school's own
 * records (students, staff, academics, ...) live in its dedicated
 * tenant database — see App\Services\Tenancy\TenantManager.
 */
class School extends Model
{
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';

    protected $fillable = [
        'name',
        'slug',
        'motto',
        'email',
        'phone',
        'address',
        'logo_path',
        'status',
        'settings',
    ];

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'provisioned_at' => 'datetime',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'school_user')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    public function isProvisioned(): bool
    {
        return $this->database !== null && $this->provisioned_at !== null;
    }
}
