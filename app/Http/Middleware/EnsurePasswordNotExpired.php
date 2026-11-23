<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

/**
 * Keeps a user pinned to the forced-change page on every fresh request —
 * login.blade.php only sends them there once, at sign-in; without this,
 * typing any other URL afterward would walk straight past it. Two
 * independent reasons land someone here:
 *
 *  - Expiry (security_settings.password_expiry_days): re-derivable from
 *    password_changed_at on every request, no state needed.
 *  - Retroactive policy enforcement: login.blade.php sets
 *    session('must_change_password') when the password just typed in
 *    doesn't meet the CURRENT length/complexity rules (set under a
 *    looser policy since tightened) — this can only be checked once, at
 *    login, with the plaintext in hand, so the session flag is what
 *    keeps it enforced afterward. Cleared by force-password-change once
 *    the password actually changes.
 *
 * livewire.update is excluded because it's one shared endpoint for
 * every page's own background actions, including this page's own form
 * submission — blocking it here would make the forced-change page
 * unable to submit itself. The accepted gap this leaves (same one
 * EnsureSessionNotExpired already lives with for timeouts) is a page
 * already open in another tab before the trigger fired: its own
 * wire:click/wire:submit calls still reach livewire.update and still
 * work until that tab is reloaded or navigated.
 */
class EnsurePasswordNotExpired
{
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()
            && ! $request->routeIs('password.force-change')
            && ! $request->routeIs('livewire.update')
            && (Auth::user()->passwordHasExpired() || $request->session()->get('must_change_password'))
        ) {
            return redirect()->route('password.force-change');
        }

        return $next($request);
    }
}
