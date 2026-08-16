<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        // Stateless "api" middleware group (no CSRF, no session) — see
        // routes/api.php's own docblock for why this exists: license
        // check-in/release-download calls come from an external
        // self-hosted server, not a browser, and would otherwise 419
        // against routes/web.php's default CSRF protection.
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        // Baseline security headers on every response, web and api
        // alike (2026-08-15): a live check of production found none of
        // them set. See the middleware's own docblock for why a full
        // Content-Security-Policy is deliberately not part of this.
        $middleware->append(\App\Http\Middleware\SecurityHeaders::class);

        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'super_admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'school_member' => \App\Http\Middleware\EnsureSchoolMember::class,
            'require_password_change' => \App\Http\Middleware\RequirePasswordChange::class,
            'applicant_active' => \App\Http\Middleware\EnsureApplicantActive::class,
            'block_demo_mutations' => \App\Http\Middleware\BlockDemoAccountMutations::class,
        ]);

        // Domain aware guest redirect (found live 2026-07-21: a session
        // expiring — idle timeout, manual logout elsewhere, ... — while
        // browsing a school's own subdomain bounced to the platform
        // admin's bare-domain login instead of that school's own branded
        // one, since Laravel's own default just calls route('login')
        // unconditionally, the one route literally named that, wherever
        // the request actually came from. Worse than a wrong page: it
        // could read as an actual login/redirect ping pong across the
        // two hosts ("the page just keeps reloading"), since a stale
        // session cookie on the bare domain can make that wrong page
        // look authenticated enough to try bouncing back to the
        // originally intended school subdomain URL, which is still not
        // authenticated there either.
        $middleware->redirectGuestsTo(function (\Illuminate\Http\Request $request) {
            $school = $request->route('school');

            return $school
                ? route('school.login', ['school' => $school])
                : route('login');
        });

        // The mirror image of the guest-redirect bug above, same root
        // cause: found live 2026-08-01, an applicant who was already
        // signed in clicked "Continue your application" (which points
        // at their own admissions login page) and landed on the bare
        // platform chooser page instead of their dashboard. Laravel's
        // own default for an ALREADY-authenticated user hitting a
        // guest-only route is unconditionally route('login') too — the
        // one route literally named that — regardless of which guard
        // (web on the bare domain, web on a school subdomain, or
        // applicant) actually holds the session. Laravel's own
        // RedirectIfAuthenticated::redirectTo() only ever forwards the
        // request here, never which guard matched, so this branches on
        // the route itself instead — the admissions login group is the
        // only guest route named school.admissions.*.
        $middleware->redirectUsersTo(function (\Illuminate\Http\Request $request) {
            $school = $request->route('school');
            $routeName = (string) $request->route()?->getName();

            if ($school && str_starts_with($routeName, 'school.admissions.')) {
                return route('school.admissions.dashboard', ['school' => $school]);
            }

            if ($school) {
                return route('school.dashboard', ['school' => $school]);
            }

            // Bare platform domain, 'web' guard — matches the '/' route's
            // own existing rule just above: only a super admin has a real
            // destination here, everyone else has nowhere better than the
            // login page they were already trying to reach.
            return \Illuminate\Support\Facades\Auth::user()?->is_super_admin
                ? route('admin.dashboard')
                : route('login');
        });
    })
    ->withExceptions(function (Exceptions $exceptions) {
        // A stale/expired CSRF token (e.g. a tab left open past the idle
        // timeout, or a form submitted after the session already expired)
        // should not dead-end on Laravel's raw 419 page. Send the visitor
        // back to where they were with a friendly message instead. Laravel
        // maps TokenMismatchException to a plain HttpException(419) before
        // custom render callbacks run, so this has to match on status code
        // rather than the original exception class.
        $exceptions->render(function (\Symfony\Component\HttpKernel\Exception\HttpException $e, \Illuminate\Http\Request $request) {
            if ($e->getStatusCode() === 419) {
                return redirect()->back()->with('error', 'Your session had expired. Please try again.');
            }
        });
    })->create();
