<?php

use Illuminate\Support\Facades\Broadcast;

// Matches App\Events\NotificationCreated::broadcastOn() — a notification
// with target_user_id broadcasts on the first, one with target_role_id
// broadcasts on the second. A user only authorizes the channels that are
// actually theirs.
Broadcast::channel('user.{id}', function ($user, $id) {
    return (int) $user->id === (int) $id;
});

Broadcast::channel('role.{roleId}', function ($user, $roleId) {
    return (int) $user->role_id === (int) $roleId;
});

/**
 * Presence channel backing the "Currently Online" dashboard widget
 * (App\Livewire\Dashboard\OnlineUsers) — any authenticated user is
 * authorized to join, since every logged-in browser tab needs to register
 * its presence for the roster to be accurate. Only the *display* of the
 * roster is admin-gated, in the Livewire component itself.
 */
Broadcast::channel('online-users', function ($user) {
    return ['id' => $user->id, 'name' => $user->name, 'role' => $user->role?->name];
});

/**
 * Live-refresh signal channels for SaleCompleted, StockChanged, and
 * CreditBalanceChanged (Dashboard, Sales History, Inventory Overview, POS
 * Terminal). Every payload on these is a no-op — just "something changed,
 * re-render" — so, same reasoning as 'online-users' above, authorization
 * here only requires being logged in; each Livewire component's render()
 * still applies its own real permission gates to decide what's actually
 * shown.
 */
Broadcast::channel('dashboard', fn ($user) => true);
Broadcast::channel('sales', fn ($user) => true);
Broadcast::channel('stock', fn ($user) => true);
