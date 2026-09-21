<?php

use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
    )
    ->withMiddleware(function (Middleware $middleware): void {
        $middleware->web(append: [
            \App\Http\Middleware\EnsureSessionNotExpired::class,
            \App\Http\Middleware\SecurityHeaders::class,
        ]);

        $middleware->alias([
            'permission' => \App\Http\Middleware\EnsurePermission::class,
            'module' => \App\Http\Middleware\EnsureModuleEnabled::class,
            'administrator' => \App\Http\Middleware\EnsureAdministrator::class,
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        // Every refused request lands in the audit log for the administrator —
        // a hook only, the normal 403 page/response is untouched (null falls through).
        $exceptions->render(function (\Throwable $e, \Illuminate\Http\Request $request) {
            $denied = $e instanceof \Illuminate\Auth\Access\AuthorizationException
                || ($e instanceof \Symfony\Component\HttpKernel\Exception\HttpExceptionInterface && $e->getStatusCode() === 403);

            if ($denied && $request->user()) {
                \App\Models\AuditLog::accessDenied($e->getMessage() ?: null);
            }

            return null;
        });
    })->create();
