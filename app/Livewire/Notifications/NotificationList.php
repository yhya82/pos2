<?php

namespace App\Livewire\Notifications;

use App\Models\SystemNotification;
use Livewire\Component;
use Livewire\WithPagination;

class NotificationList extends Component
{
    use WithPagination;

    public string $categoryFilter = '';

    public string $readFilter = '';

    public function updatingCategoryFilter(): void
    {
        $this->resetPage();
    }

    public function updatingReadFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        $user = auth()->user();

        return view('livewire.notifications.notification-list', [
            'notifications' => SystemNotification::visibleTo($user)
                ->when($this->categoryFilter, fn ($q) => $q->where('category', $this->categoryFilter))
                ->when($this->readFilter === 'unread', fn ($q) => $q->where('is_read', false))
                ->when($this->readFilter === 'read', fn ($q) => $q->where('is_read', true))
                ->orderByDesc('created_at')
                ->paginate(15),
            'unreadCount' => SystemNotification::unreadCountFor($user),
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
