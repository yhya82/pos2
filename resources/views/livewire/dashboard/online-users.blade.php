<div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 p-5">
    <div class="flex items-center gap-2 mb-4">
        <x-icon name="users" class="h-5 w-5 text-indigo-500" />
        <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ count($onlineUsers) }} Online</h3>
    </div>
    <div class="space-y-1">
        @forelse ($onlineUsers as $user)
            <div class="flex items-center gap-3 text-sm py-2 {{ ! $loop->last ? 'border-b border-gray-100 dark:border-gray-700/60' : '' }}">
                <span class="h-2 w-2 rounded-full bg-emerald-500 shrink-0"></span>
                <span class="text-gray-700 dark:text-gray-300 truncate flex-1">{{ $user['name'] }}</span>
                @if ($user['role'] ?? null)
                    <span class="text-xs text-gray-400 dark:text-gray-500 shrink-0">{{ $user['role'] }}</span>
                @endif
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">Connecting...</p>
        @endforelse
    </div>
</div>
