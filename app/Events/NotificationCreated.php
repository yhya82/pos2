<?php

namespace App\Events;

use App\Models\SystemNotification;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Broadcast by the notifications:watch console command, never fired
 * directly from a request — almost every system_notifications row is
 * created by a DB trigger, not PHP, so there's no natural place in
 * application code for this event to originate from instead.
 *
 * ShouldBroadcastNow (not ShouldBroadcast): the watcher command isn't a
 * queued job itself, and broadcasting immediately avoids needing a
 * separate queue:work process just for this.
 */
class NotificationCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public SystemNotification $notification)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        $channels = [];

        if ($this->notification->target_user_id) {
            $channels[] = new PrivateChannel('user.'.$this->notification->target_user_id);
        }

        if ($this->notification->target_role_id) {
            $channels[] = new PrivateChannel('role.'.$this->notification->target_role_id);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'NotificationCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'id' => $this->notification->id,
            'category' => $this->notification->category,
            'message' => $this->notification->message,
            'created_at' => $this->notification->created_at->toIso8601String(),
        ];
    }
}
