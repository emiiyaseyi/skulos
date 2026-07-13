<?php

namespace App\Models\Tenant;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A student record, keyed externally by admission_number. Lives in the
 * school's tenant database. Email is optional and never required.
 */
class Student extends Model
{
    use Auditable;
    use SoftDeletes;

    public const STATUS_ACTIVE = 'active';
    public const STATUS_GRADUATED = 'graduated';
    public const STATUS_WITHDRAWN = 'withdrawn';
    public const STATUS_SUSPENDED = 'suspended';

    protected $connection = 'tenant';

    protected $fillable = [
        'admission_number', 'first_name', 'last_name', 'other_names',
        'gender', 'date_of_birth', 'email', 'phone', 'parent_mobile',
        'admission_session_id', 'current_class_arm_id', 'house_id',
        'nationality', 'country', 'state_of_origin', 'lga', 'religion', 'blood_group',
        'genotype', 'previous_school', 'address', 'passport_path',
        'admitted_on', 'custom', 'status', 'user_id',
    ];

    protected function casts(): array
    {
        return [
            'date_of_birth' => 'date',
            'admitted_on' => 'date',
            'custom' => 'array',
        ];
    }

    public function guardians(): HasMany
    {
        return $this->hasMany(StudentGuardian::class);
    }

    /**
     * The platform login this student signs in with, if one has been
     * created for them. Built manually, not a plain belongsTo() — see
     * the matching comment on Staff::user()/StudentGuardian::user(), a
     * bare belongsTo() would inherit this model's tenant connection onto
     * User (a platform model), throwing "no such table: users".
     */
    public function user(): BelongsTo
    {
        $related = (new \App\Models\User())->setConnection(config('database.default'));

        return $this->newBelongsTo($related->newQuery(), $this, 'user_id', 'id', 'user');
    }

    public function enrollments(): HasMany
    {
        return $this->hasMany(Enrollment::class);
    }

    public function currentClassArm(): BelongsTo
    {
        return $this->belongsTo(ClassArm::class, 'current_class_arm_id');
    }

    public function house(): BelongsTo
    {
        return $this->belongsTo(House::class);
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
