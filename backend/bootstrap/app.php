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
