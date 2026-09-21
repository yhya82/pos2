<div class="space-y-4">
    {{-- Last 24 hours: trouble is visible without going looking for it. --}}
    @php
        $hasAlerts = $alerts['failed_logins'] > 0 || $alerts['denied'] > 0 || $alerts['tampering'] > 0 || $alerts['locked'] > 0;
    @endphp
    <div @class([
        'rounded-xl px-4 py-3 text-sm flex flex-wrap items-center gap-x-6 gap-y-1 ring-1',
        'bg-red-50 ring-red-200 text-red-900 dark:bg-red-900/20 dark:ring-red-800 dark:text-red-200' => $hasAlerts,
        'bg-emerald-50 ring-emerald-200 text-emerald-900 dark:bg-emerald-900/20 dark:ring-emerald-800 dark:text-emerald-200' => ! $hasAlerts,
    ])>
        <span class="font-semibold">Last 24 hours</span>
        @if ($hasAlerts)
            <span><strong>{{ $alerts['failed_logins'] }}</strong> failed login {{ $alerts['failed_logins'] === 1 ? 'attempt' : 'attempts' }}</span>
            <span><strong>{{ $alerts['denied'] }}</strong> access {{ $alerts['denied'] === 1 ? 'denial' : 'denials' }}</span>
            <span><strong>{{ $alerts['tampering'] }}</strong> tampered {{ $alerts['tampering'] === 1 ? 'value' : 'values' }} ignored</span>
            <span><strong>{{ $alerts['locked'] }}</strong> {{ $alerts['locked'] === 1 ? 'account' : 'accounts' }} locked now</span>
            @if ($tab !== 'security')
                <button type="button" wire:click="setTab('security')" class="ml-auto font-medium underline">Review security events</button>
            @endif
        @else
            <span>No failed logins, denied requests or tampering.</span>
        @endif
    </div>

    <div class="border-b border-gray-200 dark:border-gray-700">
        <nav class="-mb-px flex flex-wrap gap-6" aria-label="Audit views">
            @foreach (['activity' => 'Activity', 'security' => 'Security', 'people' => 'Who did what', 'movements' => 'Stock movements'] as $key => $label)
                <button type="button" wire:click="setTab('{{ $key }}')" @class([
                    'whitespace-nowrap py-2 px-1 border-b-2 text-sm font-medium',
                    'border-indigo-600 text-indigo-600 dark:border-indigo-400 dark:text-indigo-400' => $tab === $key,
                    'border-transparent text-gray-500 hover:text-gray-700 hover:border-gray-300 dark:text-gray-400 dark:hover:text-gray-200' => $tab !== $key,
                ])>{{ $label }}</button>
            @endforeach
        </nav>
    </div>

    <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 p-4">
        <div class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-5 gap-3">
            @if (in_array($tab, ['activity', 'security']))
                @if ($tab === 'activity')
                    <div>
                        <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Module</label>
                        <select wire:model.live="module" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                            <option value="">All modules</option>
                            @foreach ($modules as $m)
                                <option value="{{ $m }}">{{ str($m)->headline() }}</option>
                            @endforeach
                        </select>
                    </div>
                @endif

                <div>
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">{{ $tab === 'security' ? 'Event' : 'Action' }}</label>
                    <select wire:model.live="action" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">{{ $tab === 'security' ? 'All events' : 'All actions' }}</option>
                        @foreach ($actions as $a)
                            <option value="{{ $a }}">{{ str($a)->replace('_', ' ')->ucfirst() }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($tab === 'movements')
                <div>
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Movement</label>
                    <select wire:model.live="movementType" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">All movements</option>
                        @foreach (['stock_received', 'sale', 'return', 'damaged', 'expired', 'adjustment'] as $t)
                            <option value="{{ $t }}">{{ str($t)->replace('_', ' ')->ucfirst() }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($tab !== 'people')
                <div>
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">User</label>
                    <select wire:model.live="userId" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                        <option value="">All users</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if (in_array($tab, ['activity', 'security']))
                <div>
                    <label class="text-xs font-medium text-gray-500 dark:text-gray-400">Record Type</label>
                    <input type="text" wire:model.live.debounce.300ms="recordType" placeholder="e.g. Product" class="mt-1 block w-full text-sm rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100">
                </div>
            @endif

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

    {{-- ------------------------------------------------ Activity / Security --}}
    @if ($logs)
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40">
                        <tr>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Date/Time</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">User</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ $tab === 'security' ? 'Event' : 'Action' }}</th>
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">{{ $tab === 'security' ? 'Detail' : 'Module' }}</th>
                            @if ($tab === 'activity')
                                <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">Record</th>
                            @endif
                            <th class="px-4 py-3 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider">IP Address</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                        @forelse ($logs as $log)
                            @php
                                $isSecurity = $log->module === 'security';
                                $serious = in_array($log->action, ['tamper_ignored', 'access_denied', 'account_locked'], true);
                                $warn = in_array($log->action, ['login_failed', 'login_blocked', 'rate_limited', 'session_expired'], true);
                                $detail = $log->new_value ?? [];
                            @endphp
                            <tr wire:key="log-{{ $log->id }}" @class([
                                'hover:bg-gray-50 dark:hover:bg-gray-900/30 cursor-pointer',
                                'bg-red-50/60 dark:bg-red-900/10' => $serious,
                                'bg-amber-50/60 dark:bg-amber-900/10' => $warn,
                            ]) wire:click="toggleExpand({{ $log->id }})">
                                <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $log->created_at->format('Y-m-d H:i:s') }}</td>
                                <td class="px-4 py-3 text-sm text-gray-700 dark:text-gray-300 whitespace-nowrap">{{ $log->user?->name ?? ($isSecurity && ! empty($detail['email']) ? $detail['email'] : 'System') }}</td>
                                <td class="px-4 py-3 text-sm whitespace-nowrap">
                                    <span @class([
                                        'inline-flex px-2 py-0.5 rounded-full text-xs font-medium',
                                        'bg-emerald-100 text-emerald-800 dark:bg-emerald-900/40 dark:text-emerald-300' => in_array($log->action, ['create', 'login']),
                                        'bg-blue-100 text-blue-800 dark:bg-blue-900/40 dark:text-blue-300' => $log->action === 'update',
                                        'bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300' => $serious || in_array($log->action, ['void', 'delete']),
                                        'bg-amber-100 text-amber-800 dark:bg-amber-900/40 dark:text-amber-300' => $warn || $log->action === 'adjustment',
                                        'bg-gray-100 text-gray-700 dark:bg-gray-700 dark:text-gray-300' => $log->action === 'logout',
                                        'bg-indigo-100 text-indigo-800 dark:bg-indigo-900/40 dark:text-indigo-300' => in_array($log->action, ['receive', 'payment', 'approve']),
                                    ])>{{ str($log->action)->replace('_', ' ')->ucfirst() }}</span>
                                </td>
                                @if ($tab === 'security')
                                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 max-w-md truncate">
                                        @if ($log->action === 'access_denied')
                                            {{ $detail['method'] ?? '' }} {{ $detail['component'] ?? ($detail['path'] ?? '') }}@if (! empty($detail['call'])) → {{ $detail['call'] }}@endif @if (! empty($detail['why'])) <span class="text-gray-400">({{ $detail['why'] }})</span>@endif
                                        @elseif ($log->action === 'tamper_ignored')
                                            {{ $detail['what'] ?? '' }}
                                        @elseif (in_array($log->action, ['login_failed', 'login_blocked', 'rate_limited', 'account_locked']))
                                            {{ $detail['email'] ?? '' }}@if (isset($detail['known_account']) && ! $detail['known_account']) <span class="text-red-600 dark:text-red-400">(no such account)</span>@endif @if (! empty($detail['reason'])) — {{ $detail['reason'] }}@endif
                                        @else
                                            {{ $detail['email'] ?? '' }}
                                        @endif
                                    </td>
                                @else
                                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ str($log->module)->headline() }}</td>
                                    <td class="px-4 py-3 text-sm text-gray-600 dark:text-gray-400 whitespace-nowrap">{{ $log->record_type }}{{ $log->record_id ? ' #'.$log->record_id : '' }}</td>
                                @endif
                                <td class="px-4 py-3 text-sm text-gray-500 dark:text-gray-400 font-mono whitespace-nowrap">{{ $log->ip_address }}</td>
                                <td class="px-4 py-3 text-sm text-gray-400 whitespace-nowrap">
                                    <span class="inline-block transition-transform {{ $expandedId === $log->id ? 'rotate-180' : '' }}">&darr;</span>
                                </td>
                            </tr>
                            @if ($expandedId === $log->id)
                                <tr wire:key="log-{{ $log->id }}-detail" class="bg-gray-50 dark:bg-gray-900/40">
                                    <td colspan="7" class="px-4 py-4">
                                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-4 text-xs">
                                            @if ($isSecurity)
                                                <div class="lg:col-span-2">
                                                    <div class="font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Details</div>
                                                    <pre class="bg-white dark:bg-gray-800 rounded-md p-3 overflow-x-auto text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700">{{ $log->new_value ? json_encode($log->new_value, JSON_PRETTY_PRINT) : '—' }}</pre>
                                                </div>
                                            @else
                                                <div>
                                                    <div class="font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">Previous Value</div>
                                                    <pre class="bg-white dark:bg-gray-800 rounded-md p-3 overflow-x-auto text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700">{{ $log->previous_value ? json_encode($log->previous_value, JSON_PRETTY_PRINT) : '—' }}</pre>
                                                </div>
                                                <div>
                                                    <div class="font-semibold text-gray-500 dark:text-gray-400 uppercase tracking-wide mb-1">New Value</div>
                                                    <pre class="bg-white dark:bg-gray-800 rounded-md p-3 overflow-x-auto text-gray-700 dark:text-gray-300 ring-1 ring-gray-200 dark:ring-gray-700">{{ $log->new_value ? json_encode($log->new_value, JSON_PRETTY_PRINT) : '—' }}</pre>
                                                </div>
                                            @endif
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
                                    {{ $tab === 'security' ? 'No security events match these filters.' : 'No audit log entries match these filters.' }}
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
    @endif

    {{-- ------------------------------------------------------- Who did what --}}
    @if ($people !== null)
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 overflow-hidden">
            <div class="px-4 py-3 border-b border-gray-200 dark:border-gray-700 text-sm text-gray-600 dark:text-gray-400">
                {{ $dateFrom || $dateTo ? 'For the dates chosen above.' : 'The last 7 days — pick dates above to change it.' }}
                People with {{ $flagFailedLogins }}+ failed logins, {{ $flagDenied }}+ denied requests, or any ignored tampering are flagged.
            </div>
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Person</th>
                            <th class="px-4 py-3 text-right font-medium">Actions</th>
                            <th class="px-4 py-3 text-right font-medium">Sales</th>
                            <th class="px-4 py-3 text-right font-medium">Voids</th>
                            <th class="px-4 py-3 text-right font-medium">Refunds</th>
                            <th class="px-4 py-3 text-right font-medium">Logins</th>
                            <th class="px-4 py-3 text-right font-medium">Failed logins</th>
                            <th class="px-4 py-3 text-right font-medium">Denied</th>
                            <th class="px-4 py-3 text-right font-medium">Tampering</th>
                            <th class="px-4 py-3 text-left font-medium">Last seen</th>
                            <th class="px-4 py-3"></th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-sm tabular-nums text-gray-700 dark:text-gray-300">
                        @forelse ($people as $p)
                            @php $flagged = $p->failed_logins >= $flagFailedLogins || $p->denied >= $flagDenied || $p->tampering >= 1; @endphp
                            <tr wire:key="person-{{ $p->user_id ?? 'none' }}" @class(['bg-red-50/60 dark:bg-red-900/10' => $flagged])>
                                <td class="px-4 py-3">
                                    <div class="font-medium text-gray-900 dark:text-gray-100">{{ $p->name }}</div>
                                    @if ($p->role)<div class="text-xs text-gray-500 dark:text-gray-400">{{ $p->role }}</div>@endif
                                </td>
                                <td class="px-4 py-3 text-right">{{ (int) $p->actions }}</td>
                                <td class="px-4 py-3 text-right">{{ (int) $p->sales }}</td>
                                <td class="px-4 py-3 text-right">{{ (int) $p->voids }}</td>
                                <td class="px-4 py-3 text-right">{{ (int) $p->refunds }}</td>
                                <td class="px-4 py-3 text-right">{{ (int) $p->logins }}</td>
                                <td @class(['px-4 py-3 text-right', 'font-semibold text-red-600 dark:text-red-400' => $p->failed_logins >= $flagFailedLogins])>{{ (int) $p->failed_logins }}</td>
                                <td @class(['px-4 py-3 text-right', 'font-semibold text-red-600 dark:text-red-400' => $p->denied >= $flagDenied])>{{ (int) $p->denied }}</td>
                                <td @class(['px-4 py-3 text-right', 'font-semibold text-red-600 dark:text-red-400' => $p->tampering >= 1])>{{ (int) $p->tampering }}</td>
                                <td class="px-4 py-3 whitespace-nowrap text-gray-500 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($p->last_seen)->format('Y-m-d H:i') }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">
                                    @if ($flagged)<span class="inline-flex px-2 py-0.5 rounded-full text-xs font-medium bg-red-100 text-red-800 dark:bg-red-900/40 dark:text-red-300">Needs a look</span>@endif
                                </td>
                            </tr>
                        @empty
                            <tr><td colspan="11" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No activity in this period.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------ Stock movements --}}
    @if ($movements)
        <div class="bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 overflow-hidden">
            <div class="overflow-x-auto">
                <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                    <thead class="bg-gray-50 dark:bg-gray-900/40 text-xs text-gray-500 dark:text-gray-400 uppercase tracking-wider">
                        <tr>
                            <th class="px-4 py-3 text-left font-medium">Date/Time</th>
                            <th class="px-4 py-3 text-left font-medium">Product</th>
                            <th class="px-4 py-3 text-left font-medium">Batch</th>
                            <th class="px-4 py-3 text-left font-medium">Movement</th>
                            <th class="px-4 py-3 text-right font-medium">Change</th>
                            <th class="px-4 py-3 text-right font-medium">Batch qty</th>
                            <th class="px-4 py-3 text-left font-medium">User</th>
                            <th class="px-4 py-3 text-left font-medium">Reason</th>
                        </tr>
                    </thead>
                    <tbody class="divide-y divide-gray-200 dark:divide-gray-700 text-sm text-gray-700 dark:text-gray-300">
                        @forelse ($movements as $m)
                            <tr wire:key="movement-{{ $m->id }}">
                                <td class="px-4 py-3 whitespace-nowrap text-gray-600 dark:text-gray-400">{{ \Illuminate\Support\Carbon::parse($m->created_at)->format('Y-m-d H:i:s') }}</td>
                                <td class="px-4 py-3">{{ $m->product_name }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $m->batch_code ?: ($m->batch_id ? '#'.$m->batch_id : '—') }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ str($m->movement_type)->replace('_', ' ')->ucfirst() }}</td>
                                <td @class(['px-4 py-3 text-right tabular-nums whitespace-nowrap', 'text-emerald-600 dark:text-emerald-400' => $m->quantity > 0, 'text-red-600 dark:text-red-400' => $m->quantity < 0])>{{ ($m->quantity > 0 ? '+' : '').rtrim(rtrim(number_format((float) $m->quantity, 3), '0'), '.') }}</td>
                                <td class="px-4 py-3 text-right tabular-nums whitespace-nowrap text-gray-500 dark:text-gray-400">{{ rtrim(rtrim(number_format((float) $m->previous_qty, 3), '0'), '.') ?: '0' }} → {{ rtrim(rtrim(number_format((float) $m->new_qty, 3), '0'), '.') ?: '0' }}</td>
                                <td class="px-4 py-3 whitespace-nowrap">{{ $m->user_name }}</td>
                                <td class="px-4 py-3 text-gray-600 dark:text-gray-400 max-w-xs truncate" title="{{ $m->reason }}">{{ $m->reason ?? '—' }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="8" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No stock movements match these filters.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>

            <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                {{ $movements->links() }}
            </div>
        </div>
    @endif
</div>
