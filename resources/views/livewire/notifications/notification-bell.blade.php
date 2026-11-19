{{-- wire:poll here is now just a fallback (e.g. a dropped websocket) —
     primary delivery is the echo-private listeners in the component,
     fed by the notifications:watch background process. --}}
<div class="relative" x-data x-on:click.outside="$wire.open = false" wire:poll.60s>
    <button type="button" wire:click="toggle" class="notif-bell" title="Notifications">
        <svg viewBox="0 0 24 24"><path d="M6 10a6 6 0 1112 0c0 4 1.5 5.5 1.5 5.5h-15S6 14 6 10z"/><path d="M10 19a2 2 0 004 0"/></svg>
        @if ($unreadCount > 0)
            <span class="cnt">{{ $unreadCount > 9 ? '9+' : $unreadCount }}</span>
        @endif
    </button>

    <div x-show="$wire.open" x-transition style="display: none;" class="notif-panel">
        <div class="ph">
            <span>Notifications</span>
            @if ($unreadCount > 0)
                <button type="button" wire:click="markAllRead">Mark all read</button>
            @endif
        </div>

        <div class="list">
            @forelse ($recent as $notification)
                <button type="button" wire:click="markRead({{ $notification->id }})" class="item">
                    <div class="row1">
                        <span class="dot"></span>
                        <span class="cat">{{ $notification->category }}</span>
                        <span class="time">{{ $notification->created_at->diffForHumans() }}</span>
                    </div>
                    <p class="msg">{{ $notification->message }}</p>
                </button>
            @empty
                <p class="empty">You're all caught up, no unread notifications.</p>
            @endforelse
        </div>

        <div class="pf">
            <a href="{{ route('notifications.index') }}" wire:navigate>View all</a>
        </div>
    </div>
</div>
