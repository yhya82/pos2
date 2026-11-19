@php
    $sections = [
        'general' => ['label' => 'General Settings', 'icon' => '<circle cx="12" cy="12" r="3"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/>'],
        'store' => ['label' => 'Store Information', 'icon' => '<rect x="2" y="7" width="12" height="9"/><path d="M14 10h4l3 3v3h-7z"/><circle cx="6.5" cy="18" r="1.6"/><circle cx="16.5" cy="18" r="1.6"/>'],
        'sales' => ['label' => 'Sales Settings', 'icon' => '<path d="M5 3h14v18l-3-2-2 2-2-2-2 2-2-2-3 2V3z"/><path d="M8 8h8M8 12h8M8 16h5"/>'],
        'inventory' => ['label' => 'Inventory Settings', 'icon' => '<path d="M3 7l9-4 9 4-9 4-9-4z"/><path d="M3 12l9 4 9-4"/><path d="M3 17l9 4 9-4"/>'],
        'modules' => ['label' => 'Module Management', 'icon' => '<path d="M12 2l8 4.5v9L12 20l-8-4.5v-9z"/><path d="M4 6.5l8 4.5 8-4.5M12 11v9"/>'],
        'hardware' => ['label' => 'Hardware & Printing', 'icon' => '<rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M8 7h8M8 11h8M8 15h5"/>'],
        'notifications' => ['label' => 'Notification Settings', 'icon' => '<path d="M6 10a6 6 0 1112 0c0 4 1.5 5.5 1.5 5.5h-15S6 14 6 10z"/><path d="M10 19a2 2 0 004 0"/>'],
        'security' => ['label' => 'Security Settings', 'icon' => '<path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M9 12l2 2 4-4"/>'],
        'backup' => ['label' => 'Backup & Restore', 'icon' => '<path d="M3 15a9 3 0 0018 0V6a9 3 0 00-18 0v9z"/><path d="M3 6a9 3 0 0018 0"/>'],
        'appearance' => ['label' => 'Appearance Settings', 'icon' => '<circle cx="12" cy="12" r="9"/><circle cx="12" cy="12" r="3"/>'],
    ];
@endphp

<div class="settings-skin">
    <div class="top">
        <div>
            <h1>Settings</h1>
            <p>Store info, sales rules, modules, hardware and security organized by area</p>
        </div>
    </div>

    <div class="settlayout">
        <nav class="settnav">
            @foreach ($sections as $key => $section)
                <button type="button" wire:click="setSection('{{ $key }}')" @class(['active' => $activeSection === $key])>
                    <span class="ic">{!! '<svg viewBox="0 0 24 24">'.$section['icon'].'</svg>' !!}</span>
                    {{ $section['label'] }}
                </button>
            @endforeach
        </nav>

        <div class="settpanel">
            <div class="cardhead">
                <span class="ic">{!! '<svg viewBox="0 0 24 24">'.$sections[$activeSection]['icon'].'</svg>' !!}</span>
                <div><h2>{{ $sections[$activeSection]['label'] }}</h2></div>
            </div>

            {{-- ============================== GENERAL ============================== --}}
            @if ($activeSection === 'general')
                <form wire:submit="saveGeneral" class="cardbody">
                    <div class="f">
                        <label>Store Logo</label>
                        <div class="logo-pick">
                            <span class="box">
                                @if ($logo)
                                    <img src="{{ $logo->temporaryUrl() }}">
                                @elseif ($general['business_logo_url'])
                                    <img src="{{ $generalSettings->logoUrl() }}">
                                @else
                                    <svg viewBox="0 0 24 24"><path d="M12 2l8 4.5v9L12 20l-8-4.5v-9z"/><path d="M4 6.5l8 4.5 8-4.5M12 11v9"/></svg>
                                @endif
                            </span>
                            <div>
                                <input type="file" wire:model="logo" accept="image/*">
                                @if ($general['business_logo_url'] && ! $logo)
                                    <div><button type="button" wire:click="removeLogo" class="removelogo">Remove logo</button></div>
                                @endif
                                <x-input-error :messages="$errors->get('logo')" class="mt-1" />
                            </div>
                        </div>
                    </div>

                    <div class="f">
                        <label>Business Name<span class="req">*</span></label>
                        <input type="text" wire:model="general.business_name">
                        <x-input-error :messages="$errors->get('general.business_name')" class="mt-2" />
                    </div>

                    <div class="f grid2">
                        <div>
                            <label>Contact Phone<span class="req">*</span></label>
                            <div class="prefix">
                                <span>+220</span>
                                <input type="text" wire:model="general.contact_phone" placeholder="831234567" maxlength="9" inputmode="numeric">
                            </div>
                            @if ($contactPhoneNeedsUpdate)
                                <div class="hint warn">
                                    @if ($legacyContactPhone !== '')
                                        On file: {{ $legacyContactPhone }} — that isn't a full number. Enter the 9 digits.
                                    @else
                                        No store phone number is set yet. It's printed on receipts.
                                    @endif
                                </div>
                            @endif
                            <x-input-error :messages="$errors->get('general.contact_phone')" class="mt-2" />
                        </div>
                        <div>
                            <label>Contact Email</label>
                            <input type="email" wire:model="general.contact_email">
                            <x-input-error :messages="$errors->get('general.contact_email')" class="mt-2" />
                        </div>
                    </div>

                    <div class="f">
                        <label>Address</label>
                        <textarea wire:model="general.address" rows="2"></textarea>
                    </div>

                    <div class="f grid3">
                        <div>
                            <label>Currency Code<span class="req">*</span></label>
                            <input type="text" wire:model="general.currency_code" maxlength="3">
                            <x-input-error :messages="$errors->get('general.currency_code')" class="mt-2" />
                        </div>
                        <div>
                            <label>Date Format<span class="req">*</span></label>
                            <input type="text" wire:model="general.date_format">
                        </div>
                        <div>
                            <label>Time Format<span class="req">*</span></label>
                            <select wire:model="general.time_format">
                                <option value="24h">24h</option>
                                <option value="12h">12h</option>
                            </select>
                        </div>
                    </div>

                    <label class="check">
                        <input type="checkbox" wire:model.live="general.tax_enabled">
                        <span>Tax Enabled</span>
                    </label>
                    <div class="f" x-show="$wire.general.tax_enabled" x-cloak style="max-width:220px;">
                        <label>Tax Rate (%)<span class="req">*</span></label>
                        <input type="text" wire:model="general.tax_rate" placeholder="Tax rate %">
                        <x-input-error :messages="$errors->get('general.tax_rate')" class="mt-2" />
                    </div>

                    <div class="formfoot">
                        <button type="submit" class="btn p">Save</button>
                    </div>
                </form>
            @endif

            {{-- ============================== STORE ============================== --}}
            @if ($activeSection === 'store')
                <form wire:submit="saveStore" class="cardbody">
                    <div class="f grid2">
                        <div>
                            <label>Opening Time</label>
                            <input type="time" wire:model="store.opening_time">
                            <x-input-error :messages="$errors->get('store.opening_time')" class="mt-2" />
                        </div>
                        <div>
                            <label>Closing Time</label>
                            <input type="time" wire:model="store.closing_time">
                            <x-input-error :messages="$errors->get('store.closing_time')" class="mt-2" />
                        </div>
                    </div>
                    <div class="f" style="margin-bottom:0;">
                        <label>Receipt Business Info</label>
                        <textarea wire:model="store.receipt_business_info" rows="3" placeholder="Printed at the top of every receipt"></textarea>
                    </div>
                    <div class="formfoot">
                        <button type="submit" class="btn p">Save</button>
                    </div>
                </form>
            @endif

            {{-- ============================== SALES ============================== --}}
            @if ($activeSection === 'sales')
                <form wire:submit="saveSales" class="cardbody">
                    <div class="f" style="margin-bottom:0;">
                        <label>Default Payment Method</label>
                        <select wire:model="sales.default_payment_method_id">
                            <option value="">None</option>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="formfoot">
                        <button type="submit" class="btn p">Save</button>
                    </div>
                </form>
            @endif

            {{-- ============================== INVENTORY ============================== --}}
            @if ($activeSection === 'inventory')
                <form wire:submit="saveInventory" class="cardbody">
                    <div class="f">
                        <label>Low Stock Default Threshold<span class="req">*</span></label>
                        <input type="text" wire:model="inventory.low_stock_default_threshold">
                        <x-input-error :messages="$errors->get('inventory.low_stock_default_threshold')" class="mt-2" />
                    </div>
                    <div class="f grid3">
                        <div>
                            <label>Expiry Alert 1 (days)<span class="req">*</span></label>
                            <input type="text" wire:model="inventory.expiry_alert_days_1">
                        </div>
                        <div>
                            <label>Expiry Alert 2 (days)<span class="req">*</span></label>
                            <input type="text" wire:model="inventory.expiry_alert_days_2">
                        </div>
                        <div>
                            <label>Expiry Alert 3 (days)<span class="req">*</span></label>
                            <input type="text" wire:model="inventory.expiry_alert_days_3">
                        </div>
                    </div>
                    <p class="hint" style="margin-bottom:16px;">The daily expiry sweep raises a notification for a batch when its days-to-expiry matches any of these three windows.</p>
                    <div class="formfoot">
                        <button type="submit" class="btn p">Save</button>
                    </div>
                </form>
            @endif

            {{-- ============================== MODULES ============================== --}}
            @if ($activeSection === 'modules')
                <div class="cardbody">
                    <p class="hint" style="margin-bottom:16px;">Turning a module off hides its sidebar item and blocks its routes for everyone, immediately.</p>
                    <div class="togglelist">
                        @foreach ($moduleToggles as $moduleName => $enabled)
                            <div class="toggle-row">
                                <span class="tt">{{ str($moduleName)->headline() }}</span>
                                <button type="button" wire:click="toggleModule('{{ $moduleName }}')" @class(['toggle', 'on' => $enabled]) aria-label="Toggle {{ str($moduleName)->headline() }}">
                                    <span class="knob"></span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ============================== HARDWARE ============================== --}}
            @if ($activeSection === 'hardware')
                <form wire:submit="saveHardware" class="cardbody">
                    <label class="check">
                        <input type="checkbox" wire:model="hardware.barcode_scanner_enabled">
                        <span>Barcode Scanner Enabled</span>
                    </label>
                    <label class="check">
                        <input type="checkbox" wire:model="hardware.auto_print_receipt">
                        <span>Auto-Print Receipt</span>
                    </label>
                    <div class="f">
                        <label>Default Printer</label>
                        <input type="text" wire:model="hardware.default_printer_name">
                    </div>
                    <div class="f" style="margin-bottom:0;">
                        <label>Paper Size</label>
                        <select wire:model.live="hardware.paper_size">
                            <option value="58mm">58mm (thermal)</option>
                            <option value="80mm">80mm (thermal)</option>
                            <option value="A4">A4 (regular printer)</option>
                        </select>
                    </div>
                    <div class="formfoot">
                        <a href="{{ route('settings.print-test', ['paper_size' => $hardware['paper_size']]) }}" target="_blank" class="testprint">
                            <svg viewBox="0 0 24 24" style="width:15px;height:15px;"><rect x="4" y="3" width="16" height="18" rx="1.5"/><path d="M8 7h8M8 11h8M8 15h5"/></svg>
                            Test Print
                        </a>
                        <button type="submit" class="btn p">Save</button>
                    </div>
                </form>
            @endif

            {{-- ============================== NOTIFICATIONS ============================== --}}
            @if ($activeSection === 'notifications')
                <div class="cardbody">
                    <p class="hint" style="margin-bottom:16px;">Turns off that category of alert even if the Notifications module overall is enabled.</p>
                    <div class="togglelist">
                        @foreach ($notificationToggles as $category => $enabled)
                            <div class="toggle-row">
                                <span class="tt">{{ str($category)->headline() }}</span>
                                <button type="button" wire:click="toggleNotificationCategory('{{ $category }}')" @class(['toggle', 'on' => $enabled]) aria-label="Toggle {{ str($category)->headline() }}">
                                    <span class="knob"></span>
                                </button>
                            </div>
                        @endforeach
                    </div>
                </div>
            @endif

            {{-- ============================== SECURITY ============================== --}}
            @if ($activeSection === 'security')
                <form wire:submit="saveSecurity" class="cardbody">
                    <div class="f grid2">
                        <div>
                            <label>Session Timeout (minutes)<span class="req">*</span></label>
                            <input type="text" wire:model="security.session_timeout_minutes">
                            <x-input-error :messages="$errors->get('security.session_timeout_minutes')" class="mt-2" />
                        </div>
                        <div>
                            <label>Max Failed Login Attempts<span class="req">*</span></label>
                            <input type="text" wire:model="security.max_failed_login_attempts">
                            <x-input-error :messages="$errors->get('security.max_failed_login_attempts')" class="mt-2" />
                        </div>
                        <div>
                            <label>Lockout Duration (minutes)<span class="req">*</span></label>
                            <input type="text" wire:model="security.lockout_duration_minutes">
                            <x-input-error :messages="$errors->get('security.lockout_duration_minutes')" class="mt-2" />
                        </div>
                        <div>
                            <label>Password Minimum Length<span class="req">*</span></label>
                            <input type="text" wire:model="security.password_min_length">
                            <x-input-error :messages="$errors->get('security.password_min_length')" class="mt-2" />
                        </div>
                    </div>
                    <div class="f" style="max-width:260px;">
                        <label>Password Reset Token TTL (minutes)<span class="req">*</span></label>
                        <input type="text" wire:model="security.password_reset_token_ttl_minutes">
                        <x-input-error :messages="$errors->get('security.password_reset_token_ttl_minutes')" class="mt-2" />
                    </div>
                    <div class="formfoot">
                        <button type="submit" class="btn p">Save</button>
                    </div>
                </form>
            @endif

            {{-- ============================== BACKUP & RESTORE ============================== --}}
            @if ($activeSection === 'backup')
                <div class="cardbody wide">
                    <p class="hint" style="margin-bottom:16px; max-width:640px;">Running an actual backup (mysqldump, off-host storage) is a server-side operation, not something this page triggers directly — it's handled by the backup/restore/verify scripts documented for deployment. This shows the history those scripts leave behind.</p>
                    <div class="card">
                        <table class="tbl">
                            <thead>
                                <tr><th>Scope</th><th>Status</th><th>Created</th><th>Completed</th></tr>
                            </thead>
                            <tbody>
                                @forelse ($recentBackups as $backup)
                                    <tr>
                                        <td>{{ $backup->scope }}</td>
                                        <td>
                                            <span @class(['status', 'ok' => $backup->status === 'completed', 'acc' => $backup->status === 'failed', 'warn' => $backup->status === 'pending'])>{{ ucfirst($backup->status) }}</span>
                                        </td>
                                        <td class="mut">{{ $backup->created_at->format('Y-m-d H:i') }}</td>
                                        <td class="mut">{{ $backup->completed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                    </tr>
                                @empty
                                    <tr><td colspan="4" class="empty">No backups recorded yet.</td></tr>
                                @endforelse
                            </tbody>
                        </table>
                    </div>
                </div>
            @endif

            {{-- ============================== APPEARANCE ============================== --}}
            @if ($activeSection === 'appearance')
                <div class="cardbody">
                    <p class="hint" style="margin-bottom:16px;">Saved to your account — this only affects your own view, and applies immediately.</p>
                    <div class="themepick">
                        @foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $value => $label)
                            <button type="button" wire:click="saveTheme('{{ $value }}')" @class(['themeopt', 'active' => $theme === $value])>
                                {{ $label }}
                            </button>
                        @endforeach
                    </div>
                </div>
            @endif
        </div>
    </div>
</div>
