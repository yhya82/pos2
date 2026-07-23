<div class="space-y-4">
    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 p-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            <div>
                <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Module</label>
                <select wire:model.live="module" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All modules</option>
                    @foreach ($modules as $m)
                        <option value="{{ $m }}">{{ str($m)->headline() }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Action</label>
                <select wire:model.live="action" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All actions</option>
                    @foreach ($actions as $a)
                        <option value="{{ $a }}">{{ ucfirst($a) }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-xs font-medium text-gray-500 dark:text-gray-400">User</label>
                <select wire:model.live="userId" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                    <option value="">All users</option>
                    @foreach ($users as $u)
                        <option value="{{ $u->id }}">{{ $u->name }}</option>
                    @endforeach
                </select>
            </div>

            <div>
                <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Record Type</label>
                <input type="text" wire:model.live.debounce.300ms="recordType" placeholder="e.g. Product" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>

            <div class="flex items-end">
                <button type="button" wire:click="clearFilters" class="text-sm font-medium text-gray-500 hover:text-red-600 dark:text-gray-400 dark:hover:text-red-400">
                    Clear filters
                </button>
            </div>
        </div>

        <div class="flex flex-wrap items-center gap-3 mt-4 pt-4 border-t border-gray-100 dark:border-gray-700">
            <div class="inline-flex rounded-md shadow-sm" role="group">
                @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $value => $label)
                    <button
                        type="button"
                        wire:click="setPeriod('{{ $value }}')"
                        @class([
                            'px-3 py-1.5 text-xs font-medium border first:rounded-l-md last:rounded-r-md -ml-px first:ml-0',
                            'bg-indigo-600 text-white border-indigo-600 z-10' => $period === $value,
                            'bg-white text-gray-600 border-gray-300 hover:bg-gray-50 dark:bg-gray-900 dark:text-gray-300 dark:border-gray-700 dark:hover:bg-gray-800' => $period !== $value,
                        ])
                    >{{ $label }}</button>
                @endforeach
            </div>

            <div class="flex items-center gap-2">
                <input type="date" wire:model.live="dateFrom" class="text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                <span class="text-gray-400 text-sm">to</span>
                <input type="date" wire:model.live="dateTo" class="text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
            </div>
        </div>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 overflow-hidden">
        <div class="overflow-x-auto">
            <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                <thead class="bg-gray-50 dark:bg-gray-900/40">
                    <tr>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date/Time</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Action</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Module</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Record</th>
                        <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">IP Address</th>
                        <th class="px-4 py-3"></th>
                    </tr>
                </thead>
                <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                    @forelse ($logs as $log)
                        <tr wire:key="log-{{ $log->id }}" class="hover:bg-gray-50 dark:hover:bg-gray-900/30 cursor-pointer" wire:click="toggleExpand({{ $log->id }})">
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                            <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ $log->user?->name ?? 'System' }}</td>
                            <td class="px-4 py-3 text-sm whitespace-nowrap">
                                <span @class([
                                    'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                    'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => $log->action === 'create',
                                    'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' => $log->action === 'update',
                                    'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' => in_array($log->action, ['void', 'delete']),
                                    'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $log->action === 'adjustment',
                                    'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300' => in_array($log->action, ['receive', 'payment', 'approve']),
                                ])>{{ ucfirst($log->action) }}</span>
                            </td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ str($log->module)->headline() }}</td>
                            <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $log->record_type }} #{{ $log->record_id }}</td>
                            <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 font-mono whitespace-nowrap">{{ $log->ip_address }}</td>
                            <td class="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">
                                <span class="inline-block transition-transform {{ $expandedId === $log->id ? 'rotate-180' : '' }}">&darr;</span>
                            </td>
                        </tr>
                        @if ($expandedId === $log->id)
                            <tr wire:key="log-{{ $log->id }}-detail" class="bg-gray-50 dark:bg-gray-900/40">
                                <td colspan="7" class="px-4 py-4">
                                    <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
                                        <div>
                                            <div class="font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Previous Value</div>
                                            <pre class="bg-white dark:bg-gray-800 rounded-md p-3 overflow-x-auto text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700">{{ $log->previous_value ? json_encode($log->previous_value, JSON_PRETTY_PRINT) : '—' }}</pre>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">New Value</div>
                                            <pre class="bg-white dark:bg-gray-800 rounded-md p-3 overflow-x-auto text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700">{{ $log->new_value ? json_encode($log->new_value, JSON_PRETTY_PRINT) : '—' }}</pre>
                                        </div>
                                        <div>
                                            <div class="font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Device</div>
                                            <p class="bg-white dark:bg-gray-800 rounded-md p-3 text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700 break-words">{{ $log->device_info ?? '—' }}</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                No audit log entries match these filters.
                            </td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
            {{ $logs->links() }}
        </div>
    </div>
</div>
