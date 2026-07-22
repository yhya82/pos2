<?php

namespace App\Http\Middleware;

use App\Models\LoginSession;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces security_settings.session_timeout_minutes against the
 * login_sessions row created at login — without this, that column and
 * table would only ever be descriptive, never actually acted on.
 */
class EnsureSessionNotExpired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $session = LoginSession::findActiveBySessionId($request->session()->getId());

            if (! $session || $session->expires_at->isPast()) {
                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', 'Your session has expired. Please log in again.');
            }
        }

        return $next($request);
    }
}
