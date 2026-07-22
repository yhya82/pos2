<?php

namespace App\Http\Middleware;

use App\Models\ModuleSetting;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Server-side counterpart to the sidebar hiding disabled modules (SRS Sec.
 * 20.3) — a direct URL visit must not reach a module Settings has turned
 * off, not just have its nav link hidden. Usage:
 * ->middleware('module:purchase_management')
 */
class EnsureModuleEnabled
{
    public function handle(Request $request, Closure $next, string $module): Response
    {
        abort_unless(ModuleSetting::enabled($module), 404);

        return $next($request);
    }
}
