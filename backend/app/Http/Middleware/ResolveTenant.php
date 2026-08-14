<?php

namespace App\Http\Middleware;

use App\Models\School;
use App\Services\Mail\MailSettingsService;
use App\Services\Tenancy\TenantManager;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Resolves the active school for the request and points the tenant
 * connection at its database.
 *
 * Resolution order:
 *  1. X-School header (API clients / the future Next.js frontend)
 *  2. Subdomain ({slug}.skulosapp.com or any host with 3+ labels)
 */
class ResolveTenant
{
    public function __construct(protected TenantManager $tenants)
    {
    }

    public function handle(Request $request, Closure $next): Response
    {
        // Resolution order: route {school} parameter (subdomain routing on
        // any host, including localhost), then X-School header (API), then
        // the host's subdomain.
        $slug = $request->route('school')
            ?: $request->headers->get(config('tenancy.header'))
            ?: $this->subdomainFrom($request->getHost());

        if (! $slug) {
            abort(404, 'No school specified.');
        }

        // Case-insensitive on purpose: a slug is meant to always be stored
        // lowercase (see SchoolAdminController::store()), but a browser's
        // Host header case is outside our control, and a stray mixed-case
        // slug from any future admin path should still resolve rather than
        // 404 (found live 2026-07-12, see TenantManager::databaseNameFor()
        // for the matching fix on the database-name side of this bug).
        $school = School::whereRaw('LOWER(slug) = ?', [mb_strtolower($slug)])->first();

        if (! $school || ! $school->isProvisioned()) {
            abort(404, 'School not found.');
        }

        if ($school->status === School::STATUS_SUSPENDED) {
            abort(403, match ($school->suspension_reason) {
                School::SUSPENSION_REASON_BILLING => 'This school\'s SKULOS subscription is overdue. Please contact your school administrator to arrange payment.',
                School::SUSPENSION_REASON_LICENSE_EXPIRED => 'This school\'s SKULOS license could not be verified and the grace period has expired. Please contact SKULOS to restore access.',
                default => 'This school is currently suspended. Please contact your administrator.',
            });
        }
        if ($school->status !== School::STATUS_ACTIVE) {
            abort(403, 'This school is not currently available.');
        }

        $this->tenants->use($school);
        $request->attributes->set('school', $school);

        // This school's own SMTP settings, if it has set any, win over
        // the platform default for the rest of this request (user
        // request 2026-07-09).
        try {
            app(MailSettingsService::class)->applySchoolOverride();
        } catch (\Throwable $e) {
            // no override configured, or the Setting table has an issue —
            // fall back to whatever the platform already applied.
        }

        return $next($request);
    }

    protected function subdomainFrom(string $host): ?string
    {
        $labels = explode('.', $host);

        // "greenfield.skulosapp.com" => "greenfield"; bare domains and
        // localhost have no subdomain to extract.
        if (count($labels) < 3) {
            return null;
        }

        $first = $labels[0];

        return in_array($first, ['www', 'app', 'api'], true) ? null : $first;
    }
}
