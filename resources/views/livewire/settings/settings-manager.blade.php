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
            @unless ($activeSection === 'security')
                <div class="cardhead">
                    <span class="ic">{!! '<svg viewBox="0 0 24 24">'.$sections[$activeSection]['icon'].'</svg>' !!}</span>
                    <div><h2>{{ $sections[$activeSection]['label'] }}</h2></div>
                </div>
            @endunless

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
                    <div class="f">
                        <label>Default Payment Method</label>
                        <select wire:model="sales.default_payment_method_id">
                            <option value="">None</option>
                            @foreach ($paymentMethods as $method)
                                <option value="{{ $method->id }}">{{ $method->name }}</option>
                            @endforeach
                        </select>
                    </div>
                    <div class="f" style="margin-bottom:0;">
                        <label>Return Window (days)<span class="req">*</span></label>
                        <input type="text" wire:model="sales.return_window_days">
                        <p class="hint">A sale can no longer be returned past this many days after its sale date. 0 means no limit. Someone with the "refund any sale" permission can still process a return past the window.</p>
                        <x-input-error :messages="$errors->get('sales.return_window_days')" class="mt-2" />
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
                @php
                    $isAdmin = auth()->user()->isAdministrator();
                    $circumference = 2 * M_PI * 52;
                    $scoreOffset = $circumference * (1 - $securityScore['score'] / 100);
                @endphp
                <div class="cardbody wide">

                    <div class="secbanner">
                        <div class="bl">
                            <span class="ic"><svg viewBox="0 0 24 24"><path d="M12 3l7 3v6c0 4.5-3 7.5-7 9-4-1.5-7-4.5-7-9V6l7-3z"/><path d="M9 12l2 2 4-4"/></svg></span>
                            <div>
                                <h2>Security Center</h2>
                                <p>Security monitoring and management</p>
                            </div>
                        </div>
                        <div class="br">
                            <button type="button" class="btn" wire:click="editPasswordPolicy"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>Password Policy</button>
                            <button type="button" class="btn" wire:click="$refresh"><svg viewBox="0 0 24 24"><path d="M21 12a9 9 0 1 1-2.64-6.36"/><path d="M21 3v6h-6"/></svg>Refresh</button>
                        </div>
                    </div>

                    <div class="scorecard">
                        <div class="shead">
                            <h3>Security Score</h3>
                            <p>Reflects how strict the current settings are, see "Improve your score" below to raise it.</p>
                        </div>

                        <div class="ring">
                            <svg viewBox="0 0 120 120">
                                <circle class="track" cx="60" cy="60" r="52" fill="none" stroke-width="10"/>
                                <circle class="fill {{ $securityScore['tone'] }}" cx="60" cy="60" r="52" fill="none" stroke-width="10" stroke-dasharray="{{ $circumference }}" stroke-dashoffset="{{ $scoreOffset }}" stroke-linecap="round"/>
                            </svg>
                            <div class="num"><b>{{ $securityScore['score'] }}</b><span>out of 100</span></div>
                        </div>

                        <div class="metrics">
                            <div class="row"><span>Password policy strength</span><b class="{{ $securityPasswordStrength['tone'] }}">{{ $securityPasswordStrength['label'] }}</b></div>
                            <div class="row"><span>New-device logins (24h)</span><b class="{{ $securityStats['new_devices'] > 0 ? 'warn' : 'ok' }}">{{ $securityStats['new_devices'] }}</b></div>
                            <div class="row"><span>Failed logins (24h)</span><b class="{{ $securityStats['failed_logins'] > 0 ? 'warn' : 'mut' }}">{{ $securityStats['failed_logins'] }}</b></div>
                        </div>

                        <div class="decor"><svg viewBox="0 0 24 24"><rect x="4" y="11" width="16" height="9"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></div>
                    </div>

                    <div class="sectabs">
                        <button type="button" wire:click="setSecurityTab('overview')" @class(['active' => $securityTab === 'overview'])><svg viewBox="0 0 24 24"><rect x="3" y="3" width="7" height="7"/><rect x="14" y="3" width="7" height="7"/><rect x="3" y="14" width="7" height="7"/><rect x="14" y="14" width="7" height="7"/></svg>Overview</button>
                        <button type="button" wire:click="setSecurityTab('policy')" @class(['active' => $securityTab === 'policy'])><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>Policy</button>
                        @if ($isAdmin)
                            <button type="button" wire:click="setSecurityTab('devices')" @class(['active' => $securityTab === 'devices'])><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="12"/><path d="M8 20h8M12 16v4"/></svg>Devices<span class="ct">{{ $securityStats['devices'] }}</span></button>
                            <button type="button" wire:click="setSecurityTab('activity')" @class(['active' => $securityTab === 'activity'])><svg viewBox="0 0 24 24"><path d="M5 19V11"/><path d="M12 19V5"/><path d="M19 19v-7"/><path d="M3 19h18"/></svg>Login activity</button>
                            <button type="button" wire:click="setSecurityTab('threats')" @class(['active' => $securityTab === 'threats'])><svg viewBox="0 0 24 24"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg>Threats<span class="ct">{{ count($securityThreats ?? []) }}</span></button>
                            <button type="button" wire:click="setSecurityTab('people')" @class(['active' => $securityTab === 'people'])><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>People</button>
                        @endif
                    </div>

                    {{-- ----------------------------- Overview tab ----------------------------- --}}
                    @if ($securityTab === 'overview')
                        <div class="stattiles">
                            <div class="stile"><span class="ic plain"><svg viewBox="0 0 24 24"><path d="M15 3h4a2 2 0 0 1 2 2v14a2 2 0 0 1-2 2h-4"/><path d="M10 17l5-5-5-5"/><path d="M15 12H3"/></svg></span><b>{{ $securityStats['total_logins'] }}</b><span>Total logins</span></div>
                            <div class="stile"><span class="ic ok"><svg viewBox="0 0 24 24"><path d="M22 11.08V12a10 10 0 1 1-5.93-9.14"/><path d="M22 4 12 14.01l-3-3"/></svg></span><b>{{ $securityStats['successful_logins'] }}</b><span>Successful logins</span></div>
                            <div class="stile"><span class="ic {{ $securityStats['failed_logins'] > 0 ? 'warn' : 'plain' }}"><svg viewBox="0 0 24 24"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></span><b>{{ $securityStats['failed_logins'] }}</b><span>Failed logins</span></div>
                            <div class="stile"><span class="ic plain"><svg viewBox="0 0 24 24"><rect x="3" y="4" width="18" height="12"/><path d="M8 20h8M12 16v4"/></svg></span><b>{{ $securityStats['devices'] }}</b><span>Active sessions (currently online)</span></div>
                            <div class="stile"><span class="ic {{ $securityStats['locked'] > 0 ? 'acc' : 'plain' }}"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg></span><b>{{ $securityStats['locked'] }}</b><span>Accounts locked right now</span></div>
                        </div>

                        <div class="suggest">
                            <div class="sh">
                                <span class="ic"><svg viewBox="0 0 24 24"><path d="M9 18h6M10 22h4M12 2a6 6 0 0 0-4 10.5c.6.5 1 1.3 1 2.1V15h6v-.4c0-.8.4-1.6 1-2.1A6 6 0 0 0 12 2z"/></svg></span>
                                <div>
                                    <h4>Improve your score</h4>
                                    <p>Each of these adds points to the Security Score.</p>
                                </div>
                            </div>
                            @if (count($securitySuggestions))
                                <div class="grid">
                                    @foreach ($securitySuggestions as $tip)
                                        <div class="item"><svg viewBox="0 0 24 24"><path d="M9 18l6-6-6-6"/></svg>{{ $tip }}</div>
                                    @endforeach
                                </div>
                            @else
                                <div class="allgood">Every scored setting is already at its strongest option.</div>
                            @endif
                            <p class="note">Two-factor authentication is planned for a later update.</p>
                            <div class="suggestfoot">
                                <button type="button" class="btn p" wire:click="editPasswordPolicy"><svg viewBox="0 0 24 24"><rect x="5" y="11" width="14" height="9"/><path d="M8 11V7a4 4 0 0 1 8 0v4"/></svg>Edit Password Policy</button>
                            </div>
                        </div>
                    @endif

                    {{-- ------------------------------- Policy tab ------------------------------ --}}
                    @if ($securityTab === 'policy')
                        <div class="polwrap">
                            <div class="card polcard">
                                <div class="pochead">
                                    <div>
                                        <h3>Current password policy</h3>
                                        <p>Applies everywhere a password is set, self-service, admin reset, and the forgot-password link.</p>
                                    </div>
                                    <button type="button" class="iconbtn" wire:click="openPolicyEditor" aria-label="Edit password policy" title="Edit password policy">
                                        <svg viewBox="0 0 24 24"><path d="M17 3a2.8 2.8 0 0 1 4 4L7 21l-4 1 1-4z"/></svg>
                                    </button>
                                </div>
                                <div class="poldisplay">
                                    <div class="prow"><span>Minimum length</span><b>{{ $security['password_min_length'] }} characters</b></div>
                                    <div class="prow"><span>Require uppercase letter (A–Z)</span><b class="{{ $security['password_require_uppercase'] ? 'ok' : 'mut' }}">{{ $security['password_require_uppercase'] ? 'Yes' : 'No' }}</b></div>
                                    <div class="prow"><span>Require lowercase letter (a–z)</span><b class="{{ $security['password_require_lowercase'] ? 'ok' : 'mut' }}">{{ $security['password_require_lowercase'] ? 'Yes' : 'No' }}</b></div>
                                    <div class="prow"><span>Require a number (0–9)</span><b class="{{ $security['password_require_number'] ? 'ok' : 'mut' }}">{{ $security['password_require_number'] ? 'Yes' : 'No' }}</b></div>
                                    <div class="prow"><span>Require a symbol (e.g. ! @ # $)</span><b class="{{ $security['password_require_symbol'] ? 'ok' : 'mut' }}">{{ $security['password_require_symbol'] ? 'Yes' : 'No' }}</b></div>
                                    <div class="prow"><span>Password History</span><b>{{ $security['password_history_count'] }} passwords</b></div>
                                    <div class="prow"><span>Password expiry</span><b>{{ $security['password_expiry_days'] > 0 ? $security['password_expiry_days'].' days' : 'Off' }}</b></div>
                                </div>
                            </div>
                            <button type="button" class="btn p" wire:click="openPolicyEditor"><svg viewBox="0 0 24 24"><path d="M12 5v14M5 12h14"/></svg>Create Policy</button>
                        </div>

                        <x-modal name="password-policy-form">
                            <div class="dialog">
                                <div class="dh">
                                    <h2>Password Policy</h2>
                                    <p>These rules apply to every password set anywhere in the app, self-service, admin reset, and the forgot-password link.</p>
                                </div>
                                <form wire:submit="saveSecurity">
                                    <div class="db">
                                        <div class="subhead" style="margin-top:0;">Password requirements</div>
                                        <div class="f" style="max-width:260px;">
                                            <label>Password Minimum Length<span class="req">*</span></label>
                                            <input type="text" wire:model.live.debounce.400ms="security.password_min_length">
                                            <x-input-error :messages="$errors->get('security.password_min_length')" class="mt-2" />
                                        </div>
                                        <div class="checkgrid">
                                            <label class="check">
                                                <input type="checkbox" wire:model.live="security.password_require_uppercase">
                                                <span>Require an uppercase letter (A–Z)</span>
                                            </label>
                                            <label class="check">
                                                <input type="checkbox" wire:model.live="security.password_require_lowercase">
                                                <span>Require a lowercase letter (a–z)</span>
                                            </label>
                                            <label class="check">
                                                <input type="checkbox" wire:model.live="security.password_require_number">
                                                <span>Require a number (0–9)</span>
                                            </label>
                                            <label class="check">
                                                <input type="checkbox" wire:model.live="security.password_require_symbol">
                                                <span>Require a symbol (e.g. ! @ # $)</span>
                                            </label>
                                        </div>

                                        <div class="subhead">Reuse &amp; expiry</div>
                                        <div class="secgrid">
                                            <div class="f">
                                                <label>Password History<span class="req">*</span></label>
                                                <input type="text" wire:model.live.debounce.400ms="security.password_history_count">
                                                <div class="hint">Blocks reusing any of the last N passwords. 0 disables this.</div>
                                                <x-input-error :messages="$errors->get('security.password_history_count')" class="mt-2" />
                                            </div>
                                            <div class="f">
                                                <label>Password Expires After (days)<span class="req">*</span></label>
                                                <input type="text" wire:model.live.debounce.400ms="security.password_expiry_days">
                                                <div class="hint">Forces a password change at next login once this many days have passed. 0 disables this.</div>
                                                <x-input-error :messages="$errors->get('security.password_expiry_days')" class="mt-2" />
                                            </div>
                                        </div>

                                        <div class="subhead">Login &amp; lockout</div>
                                        <div class="secgrid">
                                            <div class="f">
                                                <label>Session Timeout (minutes)<span class="req">*</span></label>
                                                <input type="text" wire:model.live.debounce.400ms="security.session_timeout_minutes">
                                                <x-input-error :messages="$errors->get('security.session_timeout_minutes')" class="mt-2" />
                                            </div>
                                            <div class="f">
                                                <label>Max Failed Login Attempts<span class="req">*</span></label>
                                                <input type="text" wire:model.live.debounce.400ms="security.max_failed_login_attempts">
                                                <x-input-error :messages="$errors->get('security.max_failed_login_attempts')" class="mt-2" />
                                            </div>
                                            <div class="f">
                                                <label>Lockout Duration (minutes)<span class="req">*</span></label>
                                                <input type="text" wire:model.live.debounce.400ms="security.lockout_duration_minutes">
                                                <x-input-error :messages="$errors->get('security.lockout_duration_minutes')" class="mt-2" />
                                            </div>
                                        </div>
                                    </div>
                                    <div class="df">
                                        <button type="button" class="btn" wire:click="closePolicyEditor">Cancel</button>
                                        <button type="submit" class="btn p">Save Policy</button>
                                    </div>
                                </form>
                            </div>
                        </x-modal>
                    @endif

                    {{-- ------------------------------- Devices tab ------------------------------ --}}
                    @if ($securityTab === 'devices' && $isAdmin)
                        <div class="toolrow">
                            <div>
                                <h3>Signed-in devices</h3>
                                <p>Every device currently signed in, across every user, "Online now" reflects who's actually connected right now, not just who hasn't timed out yet.</p>
                            </div>
                        </div>
                        <div class="card">
                            <table class="tbl">
                                <thead>
                                    <tr><th>User</th><th>Status</th><th>Device</th><th>IP Address</th><th>Signed in</th><th>Expires</th><th></th></tr>
                                </thead>
                                <tbody>
                                    @forelse ($securityDevices as $sess)
                                        @php
                                            $isCurrent = $sess->token_hash === hash('sha256', session()->getId());
                                            // Loose match: the presence payload round-trips through the
                                            // browser (Echo -> JS -> Livewire), so it's never worth
                                            // trusting it's still the same PHP int type on the way back.
                                            // Your own session is always "right here", regardless of
                                            // whether the websocket sync has landed yet.
                                            $isOnline = $isCurrent || in_array($sess->user_id, $onlineUserIds);
                                        @endphp
                                        <tr>
                                            <td>{{ $sess->user?->name ?? 'Unknown' }}@if ($isCurrent)<span class="tag">this device</span>@endif</td>
                                            <td><span class="status {{ $isOnline ? 'ok' : 'plain' }}">{{ $isOnline ? 'Online now' : 'Away' }}</span></td>
                                            <td class="mut">{{ \Illuminate\Support\Str::limit($sess->user_agent ?? '—', 42) }}</td>
                                            <td class="mut">{{ $sess->ip_address }}</td>
                                            <td class="mut">{{ $sess->created_at->format('Y-m-d h:i A') }}</td>
                                            <td class="mut">{{ $sess->expires_at->diffForHumans() }}</td>
                                            <td>
                                                @unless ($isCurrent)
                                                    <button type="button" class="rvk" wire:click="revokeDevice({{ $sess->id }})" wire:confirm="Revoke this session? That device will be signed out."><svg viewBox="0 0 24 24"><path d="M9 21H5a2 2 0 0 1-2-2V5a2 2 0 0 1 2-2h4"/><path d="M16 17l5-5-5-5"/><path d="M21 12H9"/></svg>Revoke</button>
                                                @endunless
                                            </td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="7" class="empty">No one is currently signed in.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif

                    {{-- --------------------------- Login activity tab --------------------------- --}}
                    @if ($securityTab === 'activity' && $isAdmin)
                        <div class="toolrow">
                            <div>
                                <h3>Login activity</h3>
                                <p>Logins, failures, logouts and password changes, the most recent 50 security events.</p>
                            </div>
                            <a class="more" href="{{ route('audit-logs.index', ['tab' => 'security']) }}" wire:navigate>View full Audit Log →</a>
                        </div>
                        <div class="card">
                            <table class="tbl">
                                <thead>
                                    <tr><th>Time</th><th>Email</th><th>Event</th><th>Attempts</th><th>IP Address</th></tr>
                                </thead>
                                <tbody>
                                    @forelse ($securityActivity as $log)
                                        @php
                                            $detail = $log->new_value ?? [];
                                            $tone = match (true) {
                                                in_array($log->action, \App\Models\AuditLog::OK_ACTIONS, true) => 'ok',
                                                in_array($log->action, \App\Models\AuditLog::WARN_ACTIONS, true) => 'warn',
                                                in_array($log->action, \App\Models\AuditLog::SERIOUS_ACTIONS, true) => 'acc',
                                                default => null,
                                            };
                                        @endphp
                                        <tr>
                                            <td class="mut">{{ $log->created_at->format('Y-m-d h:i:s A') }}</td>
                                            <td>{{ $log->user?->email ?? ($detail['email'] ?? '—') }}</td>
                                            <td>@if($tone)<span class="status {{ $tone }}">{{ str($log->action)->replace('_', ' ')->ucfirst() }}</span>@else{{ str($log->action)->replace('_', ' ')->ucfirst() }}@endif</td>
                                            <td class="mut">{{ $detail['attempts'] ?? '—' }}</td>
                                            <td class="mut">{{ $log->ip_address }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="5" class="empty">No security events recorded yet.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif

                    {{-- ------------------------------- Threats tab ------------------------------- --}}
                    @if ($securityTab === 'threats' && $isAdmin)
                        <div class="toolrow">
                            <div>
                                <h3>Flagged for a look</h3>
                                <p>A curated, higher-severity slice of the security log, tampering, denied requests, lockouts and new-device logins.</p>
                            </div>
                            <a class="more" href="{{ route('audit-logs.index', ['tab' => 'security']) }}" wire:navigate>View full Audit Log →</a>
                        </div>
                        @if (count($securityThreats))
                            <div class="threatlist">
                                @foreach ($securityThreats as $log)
                                    @php
                                        $detail = $log->new_value ?? [];
                                        $warnTone = in_array($log->action, \App\Models\AuditLog::WARN_ACTIONS, true);
                                        $label = match ($log->action) {
                                            'tamper_ignored' => 'Tampered value ignored',
                                            'access_denied' => 'Access denied',
                                            'account_locked' => 'Account locked',
                                            'login_new_device' => 'New-device login flagged',
                                            'password_reused_blocked' => 'Password reuse blocked',
                                            default => str($log->action)->replace('_', ' ')->ucfirst(),
                                        };
                                    @endphp
                                    <div class="threatrow {{ $warnTone ? 'warn' : '' }}">
                                        <span class="ic"><svg viewBox="0 0 24 24"><path d="M12 9v4M12 17h.01"/><path d="M10.3 3.9 1.8 18a2 2 0 0 0 1.7 3h17a2 2 0 0 0 1.7-3L13.7 3.9a2 2 0 0 0-3.4 0z"/></svg></span>
                                        <div class="tx">
                                            <b>{{ $label }}</b>
                                            <span>{{ $detail['email'] ?? ($log->user?->email ?? '') }}</span>
                                        </div>
                                        <div class="meta">{{ $log->created_at->format('Y-m-d h:i A') }}<br>{{ $log->user?->name ?? 'System' }}</div>
                                    </div>
                                @endforeach
                            </div>
                        @else
                            <p class="hint">Nothing flagged, no tampering, denials, lockouts or new-device logins recorded yet.</p>
                        @endif
                    @endif

                    {{-- -------------------------------- People tab -------------------------------- --}}
                    @if ($securityTab === 'people' && $isAdmin)
                        <div class="toolrow">
                            <div>
                                <h3>Who looks risky</h3>
                                <p>The last 7 days' security-relevant counts per person, not sales, voids or refunds, which stay on the Audit Logs "Who did what" tab.</p>
                            </div>
                            <a class="more" href="{{ route('audit-logs.index', ['tab' => 'people']) }}" wire:navigate>View full Audit Log →</a>
                        </div>
                        <div class="card">
                            <table class="tbl">
                                <thead>
                                    <tr>
                                        <th>Person</th>
                                        <th class="num">Failed logins</th>
                                        <th class="num">Denied</th>
                                        <th class="num">Tampering</th>
                                        <th class="num">New devices</th>
                                        <th class="num">Reuse blocked</th>
                                        <th>Status</th>
                                        <th>Last seen</th>
                                    </tr>
                                </thead>
                                <tbody>
                                    @forelse ($securityPeople as $p)
                                        @php
                                            $flagged = $p->failed_logins >= \App\Models\AuditLog::FLAG_FAILED_LOGINS
                                                || $p->denied >= \App\Models\AuditLog::FLAG_DENIED
                                                || $p->tampering >= 1
                                                || $p->new_devices >= \App\Models\AuditLog::FLAG_NEW_DEVICES
                                                || $p->reuse_blocked >= \App\Models\AuditLog::FLAG_REUSE_BLOCKED
                                                || $p->lockouts >= 1;
                                            $lockedNow = $p->locked_until && \Illuminate\Support\Carbon::parse($p->locked_until)->isFuture();
                                        @endphp
                                        <tr @class(['arow-serious' => $flagged])>
                                            <td>
                                                <span style="font-weight:700;">{{ $p->name }}</span>
                                                @if ($flagged)<span class="needslook"><svg viewBox="0 0 24 24" style="width:10px;height:10px;"><path d="M12 3.5L2.5 20h19L12 3.5z"/><path d="M12 10v4.5"/></svg>Needs a look</span>@endif
                                                @if ($p->role)<div class="mut" style="font-size:11.5px; font-weight:400;">{{ $p->role }}</div>@endif
                                            </td>
                                            <td class="num">{{ (int) $p->failed_logins }}</td>
                                            <td class="num">{{ (int) $p->denied }}</td>
                                            <td class="num">{{ (int) $p->tampering }}</td>
                                            <td class="num">{{ (int) $p->new_devices }}</td>
                                            <td class="num">{{ (int) $p->reuse_blocked }}</td>
                                            <td><span class="status {{ $lockedNow ? 'acc' : 'ok' }}">{{ $lockedNow ? 'Locked now' : 'Active' }}</span></td>
                                            <td class="mut mono">{{ \Illuminate\Support\Carbon::parse($p->last_seen)->format('Y-m-d h:i A') }}</td>
                                        </tr>
                                    @empty
                                        <tr><td colspan="8" class="empty">No security events in the last 7 days.</td></tr>
                                    @endforelse
                                </tbody>
                            </table>
                        </div>
                    @endif

                </div>
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
                                        <td class="mut">{{ $backup->created_at->format('Y-m-d h:i A') }}</td>
                                        <td class="mut">{{ $backup->completed_at?->format('Y-m-d h:i A') ?? '—' }}</td>
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
                    <p class="hint" style="margin-bottom:16px;">Saved to your account, this only affects your own view, and applies immediately.</p>
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
