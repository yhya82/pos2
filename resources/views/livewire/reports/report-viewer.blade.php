@php
    // One small inline-SVG set shared by the picker, the panel header and
    // the stat tiles — reports.css carries the color (ok/warn/danger/plain)
    // via the surrounding .ic span, this only ever draws the shape.
    $icons = [
        'bar-chart' => '<path d="M3 3v18h18"/><rect x="7" y="13" width="3" height="5"/><rect x="12" y="9" width="3" height="9"/><rect x="17" y="6" width="3" height="12"/>',
        'user' => '<circle cx="12" cy="8" r="3.4"/><path d="M5.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/>',
        'card' => '<rect x="2.5" y="5.5" width="19" height="13" rx="1.5"/><path d="M2.5 9.5h19"/><path d="M6 14.5h4"/>',
        'trending-up' => '<path d="M3 17l6-6 4 4 7-8"/><path d="M15 7h5v5"/>',
        'tag' => '<path d="M12.5 3.5h5a1 1 0 011 1v5a1 1 0 01-.3.7l-9 9a1 1 0 01-1.4 0l-5.5-5.5a1 1 0 010-1.4l9-9a1 1 0 01.7-.3z"/><circle cx="16.5" cy="7.5" r="1.3"/>',
        'corner-up-left' => '<path d="M9 14l-5-5 5-5"/><path d="M4 9h10a6 6 0 010 12h-2"/>',
        'alert-triangle' => '<path d="M12 3.5L2.5 20h19L12 3.5z"/><path d="M12 10v4.5"/><circle cx="12" cy="17.2" r=".6" fill="currentColor" stroke="none"/>',
        'x-circle' => '<circle cx="12" cy="12" r="9"/><path d="M9 9l6 6M15 9l-6 6"/>',
        'layers' => '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4"/><path d="M3 17l9 4 9-4"/>',
        'package-x' => '<path d="M3 7.5l9-4 9 4v9l-9 4-9-4v-9z"/><path d="M9 15l6-6M9 9l6 6"/>',
        'shield' => '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M12 8v5M12 15.3h.01"/>',
        'wallet' => '<path d="M3 7a2 2 0 012-2h12a2 2 0 012 2v10a2 2 0 01-2 2H5a2 2 0 01-2-2V7z"/><path d="M15 12h4"/><path d="M3 9h18"/>',
    ];
@endphp

<div class="reports-skin">
    <div class="top">
        <div>
            <h1>Reports</h1>
            <p>Every reporting view already built into the schema — grouped by area, filtered by date</p>
        </div>
    </div>

    <div class="layout">
        <nav class="picker" aria-label="Reports">
            @forelse ($groupedReports as $group => $groupReports)
                <div class="picker-group">
                    <div class="picker-label">{{ $group }}</div>
                    @foreach ($groupReports as $report)
                        <button
                            type="button"
                            wire:click="selectReport('{{ $report['reportKey'] }}')"
                            @class(['picker-item', 'active' => $selectedReport === $report['reportKey']])
                        >
                            <span class="ic {{ $report['tone'] ?? '' }}">{!! '<svg viewBox="0 0 24 24">'.($icons[$report['icon']] ?? $icons['bar-chart']).'</svg>' !!}</span>
                            {{ $report['label'] }}
                        </button>
                    @endforeach
                </div>
            @empty
                <div class="picker-group">
                    <p class="mut" style="font-size:13px;">No reports available for your role.</p>
                </div>
            @endforelse
        </nav>

        <div class="panel">
            @if ($currentReport)
                <div class="phead">
                    <div class="cardhead">
                        <span class="ic {{ $currentReport['tone'] ?? '' }}">{!! '<svg viewBox="0 0 24 24">'.($icons[$currentReport['icon']] ?? $icons['bar-chart']).'</svg>' !!}</span>
                        <div>
                            <h2>{{ $currentReport['label'] }}</h2>
                            @if (isset($currentReport['hint']))
                                <p>{{ $currentReport['hint'] }}</p>
                            @endif
                        </div>
                    </div>

                    @if (isset($currentReport['dateColumn']))
                        <div class="pcontrols">
                            <div class="pills">
                                @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year', 'all' => 'All', 'custom' => 'Custom'] as $value => $label)
                                    <button type="button" wire:click="setPeriod('{{ $value }}')" @class(['active' => $period === $value])>{{ $label }}</button>
                                @endforeach
                            </div>

                            @if ($period === 'custom')
                                <div class="daterange">
                                    <input type="date" wire:model.live="dateFrom">
                                    <span>to</span>
                                    <input type="date" wire:model.live="dateTo">
                                </div>
                            @endif
                        </div>
                    @endif
                </div>

                @if ($stats)
                    <div class="stats">
                        @foreach ($stats as $stat)
                            <div class="stat">
                                <div class="lbl">{!! '<svg viewBox="0 0 24 24">'.($icons[$stat['icon']] ?? $icons['bar-chart']).'</svg>' !!}{{ $stat['label'] }}</div>
                                <div class="val {{ $stat['tone'] ?? '' }}">
                                    @if ($stat['format'] === 'money')
                                        <span class="ccy">{{ $currencyCode }}</span> {{ number_format((float) $stat['value'], 2) }}
                                    @else
                                        {{ number_format((int) $stat['value']) }}
                                    @endif
                                </div>
                                @if (isset($stat['sub']))
                                    <div class="sub">{{ $stat['sub'] }}</div>
                                @endif
                            </div>
                        @endforeach
                    </div>
                @endif

                <div class="tbl-wrap">
                    <table class="tbl">
                        <thead>
                            <tr>
                                @foreach ($currentReport['columns'] as $column)
                                    <th @class(['num' => ($column['align'] ?? 'left') === 'right', 'sorted' => ($column['sort'] ?? false) && $sortBy === $column['key']])>
                                        @if ($column['sort'] ?? false)
                                            <button type="button" wire:click="sort('{{ $column['key'] }}')">
                                                {{ $column['label'] }}
                                                @if ($sortBy === $column['key'])
                                                    <svg viewBox="0 0 24 24">{!! $sortDirection === 'asc' ? '<path d="M18 15l-6-6-6 6"/>' : '<path d="M6 9l6 6 6-6"/>' !!}</svg>
                                                @endif
                                            </button>
                                        @else
                                            {{ $column['label'] }}
                                        @endif
                                    </th>
                                @endforeach
                            </tr>
                        </thead>
                        <tbody>
                            @forelse ($rows as $row)
                                <tr wire:key="report-row-{{ $loop->index }}">
                                    @foreach ($currentReport['columns'] as $column)
                                        @php $value = $row->{$column['key']} ?? null; @endphp
                                        <td @class(['num' => ($column['align'] ?? 'left') === 'right', 'mut' => in_array($column['key'], ['sale_day', 'sale_date', 'created_at', 'last_sold_at'], true)])>
                                            @if ($column['money'] ?? false)
                                                <span class="ccy">{{ $currencyCode }}</span> {{ number_format((float) $value, 2) }}
                                            @else
                                                {{ $value }}
                                            @endif
                                        </td>
                                    @endforeach
                                </tr>
                            @empty
                                <tr>
                                    <td colspan="{{ count($currentReport['columns']) }}" class="empty">No data for this report.</td>
                                </tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>

                <div class="pager">
                    <span>Showing {{ $rows->firstItem() ?? 0 }}–{{ $rows->lastItem() ?? 0 }} of {{ $rows->total() }}</span>
                    @if ($rows->hasPages())
                        <div class="pages">
                            @foreach ($rows->getUrlRange(max(1, $rows->currentPage() - 2), min($rows->lastPage(), $rows->currentPage() + 2)) as $page => $url)
                                <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $rows->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                            @endforeach
                        </div>
                    @endif
                </div>
            @else
                <div class="empty-panel">
                    <span class="ic">{!! '<svg viewBox="0 0 24 24">'.$icons['bar-chart'].'</svg>' !!}</span>
                    Select a report from the list to view it.
                </div>
            @endif
        </div>
    </div>
</div>
