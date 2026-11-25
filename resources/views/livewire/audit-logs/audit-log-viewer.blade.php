@php
    $hasAlerts = $alerts['failed_logins'] > 0 || $alerts['denied'] > 0 || $alerts['tampering'] > 0 || $alerts['locked'] > 0 || $alerts['new_devices'] > 0;
@endphp

<div class="audit-skin">
    <div class="top">
        <div>
            <h1>Audit Logs</h1>
            <p>Every create, update, void and sign-in, append only, nothing here can be edited or removed</p>
        </div>
    </div>

    {{-- Last 24 hours: trouble is visible without going looking for it. --}}
    <div @class(['alertbar', 'hot' => $hasAlerts])>
        @if ($hasAlerts)
            <div class="astat"><b>{{ $alerts['failed_logins'] }}</b>&nbsp;failed login {{ $alerts['failed_logins'] === 1 ? 'attempt' : 'attempts' }}</div>
            <div class="astat"><b>{{ $alerts['denied'] }}</b>&nbsp;access {{ $alerts['denied'] === 1 ? 'denial' : 'denials' }}</div>
            <div class="astat"><b>{{ $alerts['tampering'] }}</b>&nbsp;tampered {{ $alerts['tampering'] === 1 ? 'value' : 'values' }} ignored</div>
            <div class="astat"><b>{{ $alerts['locked'] }}</b>&nbsp;{{ $alerts['locked'] === 1 ? 'account' : 'accounts' }} locked now</div>
            <div class="astat"><b>{{ $alerts['new_devices'] }}</b>&nbsp;new-device {{ $alerts['new_devices'] === 1 ? 'login' : 'logins' }}</div>
            @if ($tab !== 'security')
                <span class="spacer"></span>
                <button type="button" wire:click="setTab('security')">Review security events</button>
            @endif
        @else
            <span>No failed logins, denied requests, tampering, or new-device logins.</span>
        @endif
    </div>

    <div class="tabs">
        @foreach (['activity' => 'Activity', 'security' => 'Security', 'people' => 'Who did what', 'movements' => 'Stock movements'] as $key => $label)
            <button type="button" wire:click="setTab('{{ $key }}')" @class(['active' => $tab === $key])>{{ $label }}</button>
        @endforeach
    </div>

    <div class="filters">
        <div class="frow">
            @if ($tab === 'activity')
                <div class="fitem">
                    <label for="af_module">Module</label>
                    <select wire:model.live="module" id="af_module">
                        <option value="">All modules</option>
                        @foreach ($modules as $m)
                            <option value="{{ $m }}">{{ str($m)->headline() }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if (in_array($tab, ['activity', 'security']))
                <div class="fitem">
                    <label for="af_action">{{ $tab === 'security' ? 'Event' : 'Action' }}</label>
                    <select wire:model.live="action" id="af_action">
                        <option value="">{{ $tab === 'security' ? 'All events' : 'All actions' }}</option>
                        @foreach ($actions as $a)
                            <option value="{{ $a }}">{{ str($a)->replace('_', ' ')->ucfirst() }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($tab === 'movements')
                <div class="fitem">
                    <label for="af_movement">Movement</label>
                    <select wire:model.live="movementType" id="af_movement">
                        <option value="">All movements</option>
                        @foreach (['stock_received', 'sale', 'return', 'damaged', 'expired', 'adjustment'] as $t)
                            <option value="{{ $t }}">{{ str($t)->replace('_', ' ')->ucfirst() }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if ($tab !== 'people')
                <div class="fitem">
                    <label for="af_user">User</label>
                    <select wire:model.live="userId" id="af_user">
                        <option value="">All users</option>
                        @foreach ($users as $u)
                            <option value="{{ $u->id }}">{{ $u->name }}</option>
                        @endforeach
                    </select>
                </div>
            @endif

            @if (in_array($tab, ['activity', 'security']))
                <div class="fitem">
                    <label for="af_record">Record Type</label>
                    <input type="text" wire:model.live.debounce.300ms="recordType" id="af_record" placeholder="e.g. Product">
                </div>
            @endif

            <button type="button" wire:click="clearFilters" class="btnlink" style="margin-left:auto; height:36px;">Clear filters</button>
        </div>

        <div class="frow">
            <div class="pills">
                @foreach (['day' => 'Day', 'week' => 'Week', 'month' => 'Month', 'year' => 'Year'] as $value => $label)
                    <button type="button" wire:click="setPeriod('{{ $value }}')" @class(['active' => $period === $value])>{{ $label }}</button>
                @endforeach
            </div>
            <div class="daterange">
                <input type="date" wire:model.live="dateFrom">
                <span>to</span>
                <input type="date" wire:model.live="dateTo">
            </div>
        </div>
    </div>

    {{-- ------------------------------------------------ Activity / Security --}}
    @if ($logs)
        <div class="card">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>User</th>
                        <th>{{ $tab === 'security' ? 'Event' : 'Action' }}</th>
                        <th>{{ $tab === 'security' ? 'Detail' : 'Module' }}</th>
                        @if ($tab === 'activity')
                            <th>Record</th>
                        @endif
                        <th>IP Address</th>
                        <th></th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($logs as $log)
                        @php
                            $isSecurity = $log->module === 'security';
                            $serious = in_array($log->action, \App\Models\AuditLog::SERIOUS_ACTIONS, true);
                            $warn = in_array($log->action, \App\Models\AuditLog::WARN_ACTIONS, true);
                            $detail = $log->new_value ?? [];
                            $tone = match (true) {
                                $log->action === 'create' || in_array($log->action, \App\Models\AuditLog::OK_ACTIONS, true) => 'ok',
                                $log->action === 'update' => 'plain',
                                $serious || in_array($log->action, ['void', 'delete'], true) => 'acc',
                                $warn || $log->action === 'adjustment' => 'warn',
                                $log->action === 'logout' => 'plain',
                                in_array($log->action, ['receive', 'payment', 'approve'], true) => 'ok',
                                default => 'plain',
                            };
                        @endphp
                        <tr wire:key="log-{{ $log->id }}" wire:click="toggleExpand({{ $log->id }})" @class(['clickable', 'arow-serious' => $serious, 'arow-warn' => $warn && ! $serious])>
                            <td class="mut mono">{{ $log->created_at->format('Y-m-d h:i:s A') }}</td>
                            <td>{{ $log->user?->name ?? ($isSecurity && ! empty($detail['email']) ? $detail['email'] : 'System') }}</td>
                            <td><span class="abadge {{ $tone }}">{{ str($log->action)->replace('_', ' ')->ucfirst() }}</span></td>
                            @if ($tab === 'security')
                                <td class="mut">
                                    @if ($log->action === 'access_denied')
                                        {{ $detail['method'] ?? '' }} {{ $detail['component'] ?? ($detail['path'] ?? '') }}@if (! empty($detail['call'])) → {{ $detail['call'] }}@endif @if (! empty($detail['why'])) ({{ $detail['why'] }})@endif
                                    @elseif ($log->action === 'tamper_ignored')
                                        {{ $detail['what'] ?? '' }}
                                    @elseif (in_array($log->action, ['login_failed', 'login_blocked', 'account_locked']))
                                        {{ $detail['email'] ?? '' }}@if (isset($detail['known_account']) && ! $detail['known_account']) <span style="color:var(--acc);">(no such account)</span>@endif @if (! empty($detail['reason'])) — {{ $detail['reason'] }}@endif
                                    @elseif ($log->action === 'session_revoked')
                                        Revoked by {{ \App\Models\User::find($detail['revoked_by'] ?? null)?->name ?? 'an administrator' }}
                                    @else
                                        {{ $detail['email'] ?? '' }}
                                    @endif
                                </td>
                            @else
                                <td class="mut">{{ str($log->module)->headline() }}</td>
                                <td class="mut">{{ $log->record_type }}{{ $log->record_id ? ' #'.$log->record_id : '' }}</td>
                            @endif
                            <td class="mut mono">{{ $log->ip_address }}</td>
                            <td><svg viewBox="0 0 24 24" @class(['chev', 'open' => $expandedId === $log->id])><path d="M6 9l6 6 6-6"/></svg></td>
                        </tr>
                        @if ($expandedId === $log->id)
                            <tr wire:key="log-{{ $log->id }}-detail" class="expandrow">
                                <td colspan="7">
                                    <div class="expandgrid">
                                        @if ($isSecurity)
                                            <div class="blk" style="grid-column: span 2;">
                                                <h4>Details</h4>
                                                <pre>{{ $log->new_value ? json_encode($log->new_value, JSON_PRETTY_PRINT) : '—' }}</pre>
                                            </div>
                                        @else
                                            <div class="blk">
                                                <h4>Previous Value</h4>
                                                <pre>{{ $log->previous_value ? json_encode($log->previous_value, JSON_PRETTY_PRINT) : '—' }}</pre>
                                            </div>
                                            <div class="blk">
                                                <h4>New Value</h4>
                                                <pre>{{ $log->new_value ? json_encode($log->new_value, JSON_PRETTY_PRINT) : '—' }}</pre>
                                            </div>
                                        @endif
                                        <div class="blk">
                                            <h4>Device</h4>
                                            <p>{{ $log->device_info ?? '—' }}</p>
                                        </div>
                                    </div>
                                </td>
                            </tr>
                        @endif
                    @empty
                        <tr>
                            <td colspan="7" class="empty">{{ $tab === 'security' ? 'No security events match these filters.' : 'No audit log entries match these filters.' }}</td>
                        </tr>
                    @endforelse
                </tbody>
            </table>
            <div class="pager">
                <span>Showing {{ $logs->firstItem() ?? 0 }}–{{ $logs->lastItem() ?? 0 }} of {{ $logs->total() }}</span>
                @if ($logs->hasPages())
                    <div class="pages">
                        @foreach ($logs->getUrlRange(max(1, $logs->currentPage() - 2), min($logs->lastPage(), $logs->currentPage() + 2)) as $page => $url)
                            <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $logs->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif

    {{-- ------------------------------------------------------- Who did what --}}
    @if ($people !== null)
        <div class="notetext">
            {{ $dateFrom || $dateTo ? 'For the dates chosen above.' : 'The last 7 days, pick dates above to change it.' }}
            People with {{ $flagFailedLogins }}+ failed logins, {{ $flagDenied }}+ denied requests, {{ $flagNewDevices }}+ new-device logins, {{ $flagReuseBlocked }}+ blocked password reuses, any lockout, or any ignored tampering are flagged.
        </div>
        <div class="card" style="margin-top:0;">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Person</th>
                        <th class="num">Actions</th>
                        <th class="num">Sales</th>
                        <th class="num">Voids</th>
                        <th class="num">Refunds</th>
                        <th class="num">Logins</th>
                        <th class="num">Failed logins</th>
                        <th class="num">Denied</th>
                        <th class="num">Tampering</th>
                        <th class="num">New devices</th>
                        <th class="num">Reuse blocked</th>
                        <th class="num">Lockouts</th>
                        <th>Last seen</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($people as $p)
                        @php
                            $flagged = $p->failed_logins >= $flagFailedLogins
                                || $p->denied >= $flagDenied
                                || $p->tampering >= 1
                                || $p->new_devices >= $flagNewDevices
                                || $p->reuse_blocked >= $flagReuseBlocked
                                || $p->lockouts >= 1;
                        @endphp
                        <tr wire:key="person-{{ $p->user_id ?? 'none' }}" @class(['arow-serious' => $flagged])>
                            <td>
                                <span style="font-weight:700;">{{ $p->name }}</span>
                                @if ($flagged)<span class="needslook"><svg viewBox="0 0 24 24" style="width:10px;height:10px;"><path d="M12 3.5L2.5 20h19L12 3.5z"/><path d="M12 10v4.5"/></svg>Needs a look</span>@endif
                                @if ($p->role)<div class="mut" style="font-size:11.5px; font-weight:400;">{{ $p->role }}</div>@endif
                            </td>
                            <td class="num">{{ (int) $p->actions }}</td>
                            <td class="num">{{ (int) $p->sales }}</td>
                            <td class="num">{{ (int) $p->voids }}</td>
                            <td class="num">{{ (int) $p->refunds }}</td>
                            <td class="num">{{ (int) $p->logins }}</td>
                            <td @class(['num', 'flagged' => $p->failed_logins >= $flagFailedLogins])>{{ (int) $p->failed_logins }}</td>
                            <td @class(['num', 'flagged' => $p->denied >= $flagDenied])>{{ (int) $p->denied }}</td>
                            <td @class(['num', 'flagged' => $p->tampering >= 1])>{{ (int) $p->tampering }}</td>
                            <td @class(['num', 'flagged' => $p->new_devices >= $flagNewDevices])>{{ (int) $p->new_devices }}</td>
                            <td @class(['num', 'flagged' => $p->reuse_blocked >= $flagReuseBlocked])>{{ (int) $p->reuse_blocked }}</td>
                            <td @class(['num', 'flagged' => $p->lockouts >= 1])>{{ (int) $p->lockouts }}</td>
                            <td class="mut mono">{{ \Illuminate\Support\Carbon::parse($p->last_seen)->format('Y-m-d h:i A') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="13" class="empty">No activity in this period.</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif

    {{-- ------------------------------------------------------ Stock movements --}}
    @if ($movements)
        <div class="card" style="margin-top:0;">
            <table class="tbl">
                <thead>
                    <tr>
                        <th>Date/Time</th>
                        <th>Product</th>
                        <th>Batch</th>
                        <th>Movement</th>
                        <th class="num">Change</th>
                        <th class="num">Batch qty</th>
                        <th>User</th>
                        <th>Reason</th>
                    </tr>
                </thead>
                <tbody>
                    @forelse ($movements as $m)
                        <tr wire:key="movement-{{ $m->id }}">
                            <td class="mut mono">{{ \Illuminate\Support\Carbon::parse($m->created_at)->format('Y-m-d h:i:s A') }}</td>
                            <td>{{ $m->product_name }}</td>
                            <td class="mono">{{ $m->batch_code ?: ($m->batch_id ? '#'.$m->batch_id : '—') }}</td>
                            <td>{{ str($m->movement_type)->replace('_', ' ')->ucfirst() }}</td>
                            <td @class(['num', 'chg-up' => $m->quantity > 0, 'chg-down' => $m->quantity < 0])>{{ ($m->quantity > 0 ? '+' : '').rtrim(rtrim(number_format((float) $m->quantity, 3), '0'), '.') }}</td>
                            <td class="num mut">{{ rtrim(rtrim(number_format((float) $m->previous_qty, 3), '0'), '.') ?: '0' }} → {{ rtrim(rtrim(number_format((float) $m->new_qty, 3), '0'), '.') ?: '0' }}</td>
                            <td>{{ $m->user_name }}</td>
                            <td class="mut" title="{{ $m->reason }}" style="max-width:220px; overflow:hidden; text-overflow:ellipsis; white-space:nowrap;">{{ $m->reason ?? '—' }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="8" class="empty">No stock movements match these filters.</td></tr>
                    @endforelse
                </tbody>
            </table>
            <div class="pager">
                <span>Showing {{ $movements->firstItem() ?? 0 }}–{{ $movements->lastItem() ?? 0 }} of {{ $movements->total() }}</span>
                @if ($movements->hasPages())
                    <div class="pages">
                        @foreach ($movements->getUrlRange(max(1, $movements->currentPage() - 2), min($movements->lastPage(), $movements->currentPage() + 2)) as $page => $url)
                            <button type="button" wire:click="gotoPage({{ $page }})" aria-current="{{ $page === $movements->currentPage() ? 'true' : 'false' }}">{{ $page }}</button>
                        @endforeach
                    </div>
                @endif
            </div>
        </div>
    @endif
</div>
