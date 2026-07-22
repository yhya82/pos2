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
