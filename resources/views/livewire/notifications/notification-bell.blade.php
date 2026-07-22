{{-- wire:poll here is now just a fallback (e.g. a dropped websocket) —
     primary delivery is the echo-private listeners in the component,
     fed by the notifications:watch background process. --}}
<div class="relative" x-data x-on:click.outside="$wire.open = false" wire:poll.60s>
    <button type="button" wire:click="toggle" class="relative text-gray-500 dark:text-gray-400 hover:text-gray-700 dark:hover:text-gray-200" title="Notifications">
        <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
            <path stroke-linecap="round" stroke-linejoin="round" d="M14.857 17.082a23.848 23.848 0 005.454-1.31A8.967 8.967 0 0118 9.75V9A6 6 0 006 9v.75a8.967 8.967 0 01-2.312 6.022c1.733.64 3.56 1.085 5.455 1.31m5.714 0a24.255 24.255 0 01-5.714 0m5.714 0a3 3 0 11-5.714 0" />
        </svg>
        @if ($unreadCount > 0)
            <span class="absolute -top-1 -right-1 inline-flex items-center justify-center h-4 min-w-4 px-1 rounded-full bg-red-600 text-white text-[10px] font-semibold leading-none">
                {{ $unreadCount > 9 ? '9+' : $unreadCount }}
            </span>
        @endif
    </button>

    <div
        x-show="$wire.open"
        x-transition
        style="display: none;"
        class="absolute right-0 mt-2 w-80 bg-white dark:bg-gray-800 rounded-md shadow-lg ring-1 ring-black ring-opacity-5 z-50"
    >
        <div class="flex items-center justify-between px-4 py-2 border-b border-gray-100 dark:border-gray-700">
            <span class="text-sm font-semibold text-gray-700 dark:text-gray-200">Notifications</span>
            @if ($unreadCount > 0)
                <button wire:click="markAllRead" class="text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 font-medium">Mark all read</button>
            @endif
        </div>

        <div class="max-h-96 overflow-y-auto divide-y divide-gray-100 dark:divide-gray-700">
            @forelse ($recent as $notification)
                <button
                    wire:click="markRead({{ $notification->id }})"
                    class="w-full text-left px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 {{ $notification->is_read ? 'opacity-60' : '' }}"
                >
                    <div class="flex items-center gap-2">
                        <span @class([
                            'inline-block w-1.5 h-1.5 rounded-full shrink-0',
                            'bg-indigo-500' => ! $notification->is_read,
                            'bg-transparent' => $notification->is_read,
                        ])></span>
                        <span class="text-xs uppercase tracking-wide text-gray-400">{{ $notification->category }}</span>
                        <span class="text-xs text-gray-400 ml-auto">{{ $notification->created_at->diffForHumans() }}</span>
                    </div>
                    <p class="text-sm text-gray-700 dark:text-gray-300 mt-0.5">{{ $notification->message }}</p>
                </button>
            @empty
                <p class="px-4 py-6 text-sm text-gray-500 dark:text-gray-400 text-center">No notifications yet.</p>
            @endforelse
        </div>

        <div class="px-4 py-2 border-t border-gray-100 dark:border-gray-700 text-center">
            <a href="{{ route('notifications.index') }}" wire:navigate class="text-xs text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 font-medium">View all</a>
        </div>
    </div>
</div>
