<?php

namespace App\Livewire\Dashboard;

use Livewire\Attributes\On;
use Livewire\Component;

/**
 * Backed by the 'online-users' presence channel (routes/channels.php) — the
 * roster itself lives client-side (Reverb/Echo tracks join/leave natively
 * off the WebSocket connection), this component only mirrors whatever the
 * browser reports via the Echo.join(...) calls in the app layout, forwarded
 * in as plain Livewire.dispatch() events since there's no server-side
 * broadcast event driving this (unlike NotificationBell's echo-private
 * listeners).
 */
class OnlineUsers extends Component
{
    /** @var array<int, array{id: int, name: string, role: string|null}> */
    public array $onlineUsers = [];

    #[On('online-users-synced')]
    public function syncRoster(array $users): void
    {
        $this->onlineUsers = collect($users)->keyBy('id')->all();
    }

    #[On('online-user-joined')]
    public function userJoined(array $user): void
    {
        $this->onlineUsers[$user['id']] = $user;
    }

    #[On('online-user-left')]
    public function userLeft(array $user): void
    {
        unset($this->onlineUsers[$user['id']]);
    }

    public function render()
    {
        return view('livewire.dashboard.online-users');
    }
}
