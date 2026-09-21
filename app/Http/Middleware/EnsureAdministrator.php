<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * For the few areas that belong to the Administrator role alone (the audit
 * log): no permission-grid entry can open them to anyone else.
 * Usage: ->middleware('administrator')
 */
class EnsureAdministrator
{
    public function handle(Request $request, Closure $next): Response
    {
        abort_unless($request->user()?->isAdministrator(), 403);

        return $next($request);
    }
}