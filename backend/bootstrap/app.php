<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware) {
        $middleware->alias([
            'tenant' => \App\Http\Middleware\ResolveTenant::class,
            'super_admin' => \App\Http\Middleware\EnsureSuperAdmin::class,
            'school_member' => \App\Http\Middleware\EnsureSchoolMember::class,
            'require_password_change' => \App\Http\Middleware\RequirePasswordChange::class,
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
