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

    /** Distinguishes a subscription-driven lockout (SubscriptionController::demand()) from the pre-existing, unrelated manual "Suspend" action, so ResolveTenant's 403 message can actually explain why. */
    public const SUSPENSION_REASON_BILLING = 'billing';

    /** A school whose tenant database provisioned but whose owner account failed to attach afterwards (SchoolAdminController::store()/cloneSchool()), left suspended rather than silently active with no owner (audit finding L-2, 2026-07-17). */
    public const SUSPENSION_REASON_PROVISIONING_FAILED = 'provisioning_failed';

    /** A self-hosted deployment's license grace period lapsed without a successful check-in — see App\Services\Licensing\LicenseService. */
    public const SUSPENSION_REASON_LICENSE_EXPIRED = 'license_expired';

    protected $fillable = [
        'name',
        'slug',
        'motto',
        'registration_number',
        'established_year',
        'email',
        'phone',
        'alternate_phone',
        'address',
        'website',
        'logo_path',
        'stamp_path',
        'signature_path',
        'brand_color',
        'welcome_headline',
        'welcome_message',
        'login_layout',
        'login_box_side',
        'show_all_section_logos_on_login',
        'status',
        'suspension_reason',
        'settings',
        'backup_frequency',
        'backup_time',
        'plan_id',
        'price_per_student_per_term_override',
        'next_billing_date',
        'is_self_hosted',
        'license_key',
        'license_issued_at',
        'license_last_verified_at',
        'license_grace_started_at',
        'latest_available_version',
    ];

    /**
     * Branding for the school's welcome/login page, with sensible defaults
     * so a freshly created school still renders a complete page.
     */
    public function branding(): array
    {
        $layouts = array_keys(config('skulos.login_layouts', []));

        return [
            'color' => $this->brand_color ?: '#C88A2E',
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
            'price_per_student_per_term_override' => 'decimal:2',
            'show_all_section_logos_on_login' => 'boolean',
            'is_self_hosted' => 'boolean',
            'license_issued_at' => 'datetime',
            'license_last_verified_at' => 'datetime',
            'license_grace_started_at' => 'datetime',
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

    /**
     * Whether this school's plan includes at least one feature from a
     * `plan_feature_catalog` category (e.g. "finance", "learning").
     * Whole functional areas that have no screen built yet (Admissions,
     * Finance, HR, Learning/LMS, CBT Examination) still need to
     * "automatically appear" once a plan includes them (user request
     * 2026-07-11), even before there is a real page behind them, so the
     * nav can show them as a category level "Soon" entry rather than
     * gating on one specific feature key that does not exist as a
     * screen yet either way.
     */
    public function hasAnyFeatureInCategory(string $categoryKey): bool
    {
        $plan = $this->plan ?? Plan::where('is_default', true)->first();
        $categoryFeatures = array_keys(config("skulos.plan_feature_catalog.{$categoryKey}.features", []));

        return (bool) array_intersect($categoryFeatures, $plan?->features ?? []);
    }

    public function isProvisioned(): bool
    {
        return $this->database !== null && $this->provisioned_at !== null;
    }

    /**
     * The school's own subdomain URL, e.g. https://greenfield.skulosapp.com
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
