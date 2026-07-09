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
 *  2. Subdomain ({slug}.skulos.app or any host with 3+ labels)
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

        $school = School::where('slug', $slug)->first();

        if (! $school || ! $school->isProvisioned()) {
            abort(404, 'School not found.');
        }

        if ($school->status === School::STATUS_SUSPENDED) {
            abort(403, 'This school is currently suspended. Please contact your administrator.');
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

        // "greenfield.skulos.app" => "greenfield"; bare domains and
        // localhost have no subdomain to extract.
        if (count($labels) < 3) {
            return null;
        }

        $first = $labels[0];

        return in_array($first, ['www', 'app', 'api'], true) ? null : $first;
    }
}
