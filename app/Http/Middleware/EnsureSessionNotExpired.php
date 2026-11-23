<?php

namespace App\Http\Middleware;

use App\Models\LoginSession;
use App\Models\SecuritySetting;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces security_settings.session_timeout_minutes against the
 * login_sessions row created at login — without this, that column and
 * table would only ever be descriptive, never actually acted on.
 *
 * An idle timeout, not a fixed session length: every authenticated request
 * pushes expires_at forward again, so only genuinely sitting idle for the
 * configured number of minutes ever logs someone out — being active the
 * whole time never trips it, matching what the Security Score's own
 * suggestion text already promises ("sign idle users out after...").
 */
class EnsureSessionNotExpired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $session = LoginSession::findActiveBySessionId($request->session()->getId());

            if (! $session || $session->expires_at->isPast()) {
                \App\Models\AuditLog::security('session_expired', Auth::id(), ['had_session_record' => (bool) $session], 'User');

                Auth::guard('web')->logout();
                $request->session()->invalidate();
                $request->session()->regenerateToken();

                return redirect()->route('login')->with('status', 'Your session has expired. Please log in again.');
            }

            $session->update(['expires_at' => now()->addMinutes(SecuritySetting::current()->session_timeout_minutes)]);
        }

        return $next($request);
    }
}
