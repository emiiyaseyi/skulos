<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Notifications\Notifiable;

/**
 * One platform-wide identity (TD-005): the same account is a parent at one
 * school and, say, a teacher at another. Parent unique ID = email (unique
 * at platform level). School-scoped data about people (student records,
 * staff files) lives in tenant databases and references this id.
 *
 * Security: is_super_admin is intentionally NOT mass assignable — it can
 * only be flipped explicitly via forceFill/console, never from request input.
 */
class User extends Authenticatable
{
    /** @use HasFactory<\Database\Factories\UserFactory> */
    use HasFactory, Notifiable;

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'name',
        'email',
        'phone',
        'password',
    ];

    /**
     * The attributes that should be hidden for serialization.
     *
     * @var list<string>
     */
    protected $hidden = [
        'password',
        'remember_token',
        'is_super_admin',
    ];

    /**
     * Get the attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'email_verified_at' => 'datetime',
            'password' => 'hashed',
            'is_super_admin' => 'boolean',
        ];
    }

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_user')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    /**
     * The user's role within one school (null if not a member).
     */
    public function roleIn(School $school): ?Role
    {
        $membership = $this->schools()
            ->where('schools.id', $school->id)
            ->first()?->pivot;

        return $membership?->role_id ? Role::find($membership->role_id) : null;
    }

    public function isMemberOf(School $school): bool
    {
        return $this->schools()
            ->where('schools.id', $school->id)
            ->wherePivot('status', 'active')
            ->exists();
    }
}
