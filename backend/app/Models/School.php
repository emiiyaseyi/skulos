<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

/**
 * A tenant. Lives in the platform database; all of the school's own
 * records (students, staff, academics, ...) live in its dedicated
 * tenant database — see App\Services\Tenancy\TenantManager.
 */
class School extends Model
{
    use Auditable;
    use HasFactory;
    use SoftDeletes;

    public const STATUS_PENDING = 'pending';
    public const STATUS_ACTIVE = 'active';
    public const STATUS_SUSPENDED = 'suspended';
    public const STATUS_ARCHIVED = 'archived';

    protected $fillable = [
        'name',
        'slug',
        'motto',
        'email',
        'phone',
        'address',
        'logo_path',
        'brand_color',
        'welcome_headline',
        'welcome_message',
        'login_layout',
        'login_box_side',
        'status',
        'settings',
        'backup_frequency',
        'backup_time',
        'plan_id',
        'next_billing_date',
    ];

    /**
     * Branding for the school's welcome/login page, with sensible defaults
     * so a freshly created school still renders a complete page.
     */
    public function branding(): array
    {
        $layouts = array_keys(config('skulos.login_layouts', []));

        return [
            'color' => $this->brand_color ?: '#E8A33D',
            'logo' => $this->logo_path,
            'headline' => $this->welcome_headline ?: "Welcome to {$this->name}",
            'message' => $this->welcome_message ?: 'Sign in to continue to your school portal.',
            'login_layout' => in_array($this->login_layout, $layouts, true)
                ? $this->login_layout
                : config('skulos.default_login_layout', 'centered'),
            'login_box_side' => in_array($this->login_box_side, ['left', 'right'], true)
                ? $this->login_box_side
                : 'right',
        ];
    }

    protected function casts(): array
    {
        return [
            'settings' => 'array',
            'provisioned_at' => 'datetime',
            'next_billing_date' => 'date',
        ];
    }

    public function users(): BelongsToMany
    {
        return $this->belongsToMany(User::class, 'school_user')
            ->withPivot(['role_id', 'status'])
            ->withTimestamps();
    }

    public function plan(): BelongsTo
    {
        return $this->belongsTo(Plan::class);
    }

    /**
     * Whether this school's plan includes a `plan_feature_catalog` key
     * (user request 2026-07-11: "once a plan is given to a school, it
     * should automatically appear under the school end for respective
     * users" — if it's a plan/feature that was given to a school, the
     * feature should appear appropriately; if it's not a plan/feature
     * that was given to a school, the feature should not appear at all,
     * hidden entirely, not shown locked/greyed out). A school with no
     * plan assigned yet falls back to the platform's default plan
     * (Plan::is_default), so a brand new/trial school is not treated as
     * having zero features while its billing is still being set up.
     */
    public function hasFeature(string $key): bool
    {
        $plan = $this->plan ?? Plan::where('is_default', true)->first();

        return in_array($key, $plan?->features ?? [], true);
    }

    public function isProvisioned(): bool
    {
        return $this->database !== null && $this->provisioned_at !== null;
    }

    /**
     * The school's own subdomain URL, e.g. https://greenfield.skulos.app
     * (or http://greenfield.localhost:8000 in local dev).
     */
    public function portalUrl(string $path = ''): string
    {
        $base = parse_url(config('app.url'));
        $scheme = $base['scheme'] ?? 'http';
        $domain = config('app.domain', $base['host'] ?? 'localhost');
        $port = isset($base['port']) && ! in_array($base['port'], [80, 443]) ? ':'.$base['port'] : '';

        return "{$scheme}://{$this->slug}.{$domain}{$port}".($path ? '/'.ltrim($path, '/') : '');
    }
}
