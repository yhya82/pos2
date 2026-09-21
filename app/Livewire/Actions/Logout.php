<?php

namespace App\Livewire\Actions;

use App\Models\LoginSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class Logout
{
    /**
     * Log the current user out of the application.
     */
    public function __invoke(): void
    {
        \App\Models\AuditLog::security('logout', auth()->id(), null, 'User');

        LoginSession::revokeBySessionId(session()->getId());

        Auth::guard('web')->logout();

        Session::invalidate();
        Session::regenerateToken();
    }
}
