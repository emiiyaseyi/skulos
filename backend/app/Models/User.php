<?php

namespace App\Models;

// use Illuminate\Contracts\Auth\MustVerifyEmail;
use App\Models\Concerns\Auditable;
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
    use Auditable, HasFactory, Notifiable;

    /**
     * Explicit override, not just relying on Eloquent's "no $connection
     * set -> use the default" fallback: Laravel's MorphTo::createModelByType()
     * only applies that fallback when getConnectionName() returns falsy,
     * and otherwise silently forces the related model onto the SAME
     * connection as the polymorphic PARENT (see MorphTo's own source) -
     * a real problem the moment a tenant-connection model (e.g.
     * CbtSession::takeable()) morphs to this platform-connection model,
     * since it would otherwise get queried against the wrong (tenant)
     * database entirely. Always returning a real value here, not null,
     * bypasses that fallback. Found live via CbtLiveMonitorTest/
     * CbtIncidentController, Phase 2g.
     */
    public function getConnectionName()
    {
        return config('database.default');
    }

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
            'must_change_password' => 'boolean',
            'password_changed_at' => 'datetime',
        ];
    }

    public function schools(): BelongsToMany
    {
        return $this->belongsToMany(School::class, 'school_user')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    /**
     * Instance-level memoization for roleIn()/isMemberOf() (2026-08-13,
     * a real performance finding: nav-school.blade.php alone calls
     * Rbac::check() 16+ times per page render, each one re-querying
     * this same user's role/membership from scratch — measured at 153
     * real SQL queries for those 16 checks, ~10 queries each, almost
     * entirely this kind of repeated lookup). Keyed by school id, not
     * object identity, so it works even if callers pass different
     * School instances for the same real school. Instance-level (not
     * static) — safe across requests/tests since Auth::user() returns
     * the same cached User instance throughout one request, and each
     * new request/test gets a fresh instance with an empty cache.
     */
    protected array $roleInCache = [];

    protected array $isMemberOfCache = [];

    /**
     * The user's CURRENTLY ACTIVE role within one school (null if not a
     * member) -- their primary school_user.role_id by default, or
     * whichever additional role they have switched into for this school
     * (2026-09-11, multi-role accounts), via the top-bar role switcher.
     * This is the one choke point ~30 call sites across the app already
     * depend on (Rbac::check(), RoleDashboard::build(), nav-school.blade.php,
     * the post-login redirect, TeacherScope/FamilyScope, ...) to mean
     * "this user's current effective role" -- making this single method
     * session-aware means every one of them respects a switch automatically,
     * with zero changes needed anywhere else.
     *
     * The session value is NEVER trusted blindly: it must be this exact
     * user's own primary role, or a row they actually hold in
     * user_additional_roles for THIS school, checked fresh on every
     * cache-miss. A tampered, stale, or since-revoked value silently
     * falls back to the primary role rather than switching into
     * something no longer held, or erroring.
     */
    public function roleIn(School $school): ?Role
    {
        if (! array_key_exists($school->id, $this->roleInCache)) {
            $membership = $this->schools()
                ->where('schools.id', $school->id)
                ->first()?->pivot;

            $primaryRoleId = $membership?->role_id;
            $activeRoleId = $primaryRoleId;

            if (app()->bound('session')) {
                $switchedRoleId = session('active_role_'.$school->id);
                if ($switchedRoleId && (int) $switchedRoleId !== (int) $primaryRoleId) {
                    $holds = UserAdditionalRole::where('school_id', $school->id)
                        ->where('user_id', $this->id)
                        ->where('role_id', $switchedRoleId)
                        ->exists();
                    if ($holds) {
                        $activeRoleId = $switchedRoleId;
                    }
                }
            }

            $this->roleInCache[$school->id] = $activeRoleId ? Role::find($activeRoleId) : null;
        }

        return $this->roleInCache[$school->id];
    }

    public function isMemberOf(School $school): bool
    {
        if (! array_key_exists($school->id, $this->isMemberOfCache)) {
            $this->isMemberOfCache[$school->id] = $this->schools()
                ->where('schools.id', $school->id)
                ->wherePivot('status', 'active')
                ->exists();
        }

        return $this->isMemberOfCache[$school->id];
    }
}
