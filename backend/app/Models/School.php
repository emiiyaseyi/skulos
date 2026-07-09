<?php

namespace App\Models;

use App\Models\Concerns\Auditable;
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
    use Auditable;
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
        'brand_color',
        'welcome_headline',
        'welcome_message',
        'status',
        'settings',
    ];

    /**
     * Branding for the school's welcome/login page, with sensible defaults
     * so a freshly created school still renders a complete page.
     */
    public function branding(): array
    {
        return [
            'color' => $this->brand_color ?: '#E8A33D',
            'logo' => $this->logo_path,
            'headline' => $this->welcome_headline ?: "Welcome to {$this->name}",
            'message' => $this->welcome_message ?: 'Sign in to continue to your school portal.',
        ];
    }

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
