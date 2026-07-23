<div class="grid grid-cols-1 lg:grid-cols-4 gap-4 items-start">
    <div class="lg:col-span-1 bg-white dark:bg-gray-800 shadow sm:rounded-lg p-4 space-y-4">
        @forelse ($groupedReports as $group => $reports)
            <div>
                <div class="text-xs font-semibold uppercase tracking-wide text-gray-400 mb-2">{{ $group }}</div>
                <div class="space-y-1">
                    @foreach ($reports as $report)
                        <button
                            wire:click="selectReport('{{ $report['reportKey'] }}')"
                            @class([
                                'w-full text-left flex items-center gap-2 rounded-md px-3 py-2 text-sm',
                                'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 font-medium' => $selectedReport === $report['reportKey'],
                                'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $selectedReport !== $report['reportKey'],
                            ])
                        >
                            <x-icon name="chart-bar" class="h-4 w-4 shrink-0" />
                            {{ $report['label'] }}
                        </button>
                    @endforeach
                </div>
            </div>
        @empty
            <p class="text-sm text-gray-500 dark:text-gray-400">No reports available for your role.</p>
        @endforelse
    </div>

    <div class="lg:col-span-3">
        @if ($currentReport)
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg overflow-hidden">
                <div class="p-4 border-b border-gray-200 dark:border-gray-700 flex flex-wrap items-center justify-between gap-3">
                    <h3 class="font-semibold text-gray-800 dark:text-gray-100">{{ $currentReport['label'] }}</h3>

                    @if (isset($currentReport['dateColumn']))
                        <div class="flex flex-wrap items-center gap-3">
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
                    @endif
                </div>

                <div class="overflow-x-auto">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                @foreach ($currentReport['columns'] as $column)
                                    <th @class([
                                        'px-4 py-3 text-xs font-medium text-gray-500 dark:text-gray-400 uppercase tracking-wider',
                                        'text-right' => ($column['align'] ?? 'left') === 'right',
                                        'text-left' => ($column['align'] ?? 'left') === 'left',
                                    ])>
                                        @if ($column['sort'] ?? false)
                                            <button wire:click="sort('{{ $column['key'] }}')" class="inline-flex items-center gap-1 hover:text-gray-700 dark:hover:text-gray-200">
                                                {{ $column['label'] }}
                                                @if ($sortBy === $column['key'])
                                                    <span>{{ $sortDirection === 'asc' ? '↑' : '↓' }}</span>
                                                @endif
                                            </button>
                                        @else
                                            {{ $column['label'] }}
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($rows as $row)
                                <tr>
                                    @foreach ($currentReport['columns'] as $column)
                                        <td @class([
                                            'px-4 py-3 text-sm whitespace-nowrap',
                                            'text-right tabular-nums text-gray-600 dark:text-gray-400' => ($column['align'] ?? 'left') === 'right',
                                            'text-gray-700 dark:text-gray-300' => ($column['align'] ?? 'left') === 'left',
                                        ])>
                                            @php $value = $row->{$column['key']} ?? null; @endphp
                                            {{ ($column['money'] ?? false) ? number_format((float) $value, 2) : $value }}
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($currentReport['columns']) }}" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">
                                        No data for this report.
                                    </td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="px-4 py-3 border-t border-gray-200 dark:border-gray-700">
                    {{ $rows->links() }}
                </div>
            </div>
        @else
            <div class="bg-white dark:bg-gray-800 shadow sm:rounded-lg p-10 text-center text-sm text-gray-500 dark:text-gray-400">
                Select a report from the list to view it.
            </div>
        @endif
    </div>
</div>
