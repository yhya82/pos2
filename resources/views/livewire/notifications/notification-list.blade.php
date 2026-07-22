<div>
    <div class="flex items-center justify-between gap-4 mb-4">
        <div class="flex items-center gap-3">
            <select wire:model.live="categoryFilter" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                <option value="">All categories</option>
                <option value="inventory">Inventory</option>
                <option value="sales">Sales</option>
                <option value="customer">Customer</option>
                <option value="user_system">System</option>
            </select>
            <select wire:model.live="readFilter" class="rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500 text-sm">
                <option value="">All</option>
                <option value="unread">Unread</option>
                <option value="read">Read</option>
            </select>
        </div>

        @if ($unreadCount > 0)
            <x-secondary-button wire:click="markAllRead">Mark all read</x-secondary-button>
        @endif
    </div>

    <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg divide-y divide-gray-100 dark:divide-gray-700">
        @forelse ($notifications as $notification)
            <button
                wire:click="markRead({{ $notification->id }})"
                class="w-full text-left px-4 py-3 hover:bg-gray-50 dark:hover:bg-gray-700/50 flex items-start gap-3"
            >
                <span @class([
                    'mt-1.5 inline-block w-2 h-2 rounded-full shrink-0',
                    'bg-indigo-500' => ! $notification->is_read,
                    'bg-gray-300 dark:bg-gray-600' => $notification->is_read,
                ])></span>
                <div class="min-w-0 flex-1">
                    <div class="flex items-center gap-2">
                        <span class="text-xs uppercase tracking-wide text-gray-400 font-medium">{{ str($notification->category)->headline() }}</span>
                        <span class="text-xs text-gray-400">{{ $notification->created_at->format('Y-m-d H:i') }} · {{ $notification->created_at->diffForHumans() }}</span>
                    </div>
                    <p @class(['text-sm mt-0.5', 'text-gray-900 dark:text-gray-100 font-medium' => ! $notification->is_read, 'text-gray-600 dark:text-gray-400' => $notification->is_read])>
                        {{ $notification->message }}
                    </p>
                </div>
            </button>
        @empty
            <p class="px-4 py-8 text-center text-sm text-gray-500 dark:text-gray-400">No notifications found.</p>
        @endforelse
    </div>

    <div class="mt-4">
        {{ $notifications->links() }}
    </div>
</div>
