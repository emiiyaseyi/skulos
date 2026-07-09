<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Gate for the platform super admin area. A non super admin gets a 404 so
 * the area's existence is not advertised.
 */
class EnsureSuperAdmin
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->is_super_admin, 404);

        return $next($request);
    }
}
