<?php

namespace App\Livewire\Notifications;

use App\Models\SystemNotification;
use Livewire\Attributes\On;
use Livewire\Component;

class NotificationBell extends Component
{
    public bool $open = false;

    /**
     * Exposed so the #[On('echo-private:...')] attributes below can
     * interpolate them into the channel name — Livewire's echo integration
     * only supports referencing public properties, not calling auth() from
     * inside the attribute string.
     */
    public int $userId;

    public int $roleId;

    public function mount(): void
    {
        $this->userId = auth()->id();
        $this->roleId = auth()->user()->role_id;
    }

    public function toggle(): void
    {
        $this->open = ! $this->open;
    }

    /**
     * Real-time delivery: the notifications:watch console command
     * broadcasts NotificationCreated the moment it notices a new DB row
     * (almost every row here comes from a trigger, not this app), and
     * these two listeners are what make that arrive without the 30s
     * fallback poll below ever needing to fire.
     */
    #[On('echo-private:user.{userId},.NotificationCreated')]
    #[On('echo-private:role.{roleId},.NotificationCreated')]
    public function onNotificationCreated(): void
    {
        // No-op: the payload isn't used directly, this just needs to
        // trigger a re-render so render() re-queries the unread count and
        // (if open) the recent list.
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.notifications.notification-bell', [
            'unreadCount' => SystemNotification::unreadCountFor($user),
            'recent' => $this->open
                ? SystemNotification::visibleTo($user)->orderByDesc('created_at')->limit(8)->get()
                : collect(),
        ]);
    }

    public function markRead(int $notificationId): void
    {
        SystemNotification::visibleTo(auth()->user())
            ->whereKey($notificationId)
            ->update(['is_read' => true]);
    }

    public function markAllRead(): void
    {
        SystemNotification::where('is_read', false)
            ->visibleTo(auth()->user())
            ->update(['is_read' => true]);
    }
}
