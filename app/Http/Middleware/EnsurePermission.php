<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side enforcement of the module×action permission grid — the
 * sidebar hiding a nav item is a UX convenience, not access control. Usage:
 * ->middleware('permission:products,view')
 */
class EnsurePermission
{
    public function handle(Request $request, Closure $next, string $module, string $action): Response
    {
        abort_unless($request->user()?->hasPermission($module, $action), 403);

        return $next($request);
    }
}
