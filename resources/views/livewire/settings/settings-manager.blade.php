@php
    $sections = [
        'general' => ['label' => 'General Settings', 'icon' => 'cog'],
        'store' => ['label' => 'Store Information', 'icon' => 'truck'],
        'sales' => ['label' => 'Sales Settings', 'icon' => 'banknotes'],
        'inventory' => ['label' => 'Inventory Settings', 'icon' => 'archive-box'],
        'modules' => ['label' => 'Module Management', 'icon' => 'cube'],
        'hardware' => ['label' => 'Hardware & Printing', 'icon' => 'clipboard-check'],
        'notifications' => ['label' => 'Notification Settings', 'icon' => 'bell'],
        'security' => ['label' => 'Security Settings', 'icon' => 'shield-check'],
        'backup' => ['label' => 'Backup & Restore', 'icon' => 'document-text'],
        'appearance' => ['label' => 'Appearance Settings', 'icon' => 'user-circle'],
    ];
@endphp

<div
    class="grid grid-cols-1 lg:grid-cols-4 gap-4 items-start"
    x-data
    x-on:theme-changed.window="document.documentElement.classList.toggle('dark',
        $event.detail.theme === 'dark' ||
        ($event.detail.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
    )"
>
    <div class="lg:col-span-1 bg-white dark:bg-gray-800 shadow sm:rounded-lg p-3 space-y-1">
        @foreach ($sections as $key => $section)
            <button
                wire:click="setSection('{{ $key }}')"
                @class([
                    'w-full text-left flex items-center gap-2.5 rounded-md px-3 py-2 text-sm',
                    'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300 font-medium' => $activeSection === $key,
                    'text-gray-600 hover:bg-gray-100 dark:text-gray-300 dark:hover:bg-gray-700' => $activeSection !== $key,
                ])
            >
                <x-icon :name="$section['icon']" class="h-4 w-4 shrink-0" />
                {{ $section['label'] }}
            </button>
        @endforeach
    </div>

    <div class="lg:col-span-3 bg-white dark:bg-gray-800 shadow sm:rounded-lg p-6">

        {{-- ============================== GENERAL ============================== --}}
        @if ($activeSection === 'general')
            <form wire:submit="saveGeneral" class="space-y-4 max-w-lg">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">General Settings</h3>
                <div>
                    <x-input-label value="Business Name" />
                    <x-text-input wire:model="general.business_name" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('general.business_name')" class="mt-2" />
                </div>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label value="Contact Phone" />
                        <x-text-input wire:model="general.contact_phone" class="block mt-1 w-full" />
                    </div>
                    <div>
                        <x-input-label value="Contact Email" />
                        <x-text-input wire:model="general.contact_email" type="email" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('general.contact_email')" class="mt-2" />
                    </div>
                </div>
                <div>
                    <x-input-label value="Address" />
                    <textarea wire:model="general.address" rows="2" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                </div>
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <x-input-label value="Currency Code" />
                        <x-text-input wire:model="general.currency_code" class="block mt-1 w-full" maxlength="3" />
                        <x-input-error :messages="$errors->get('general.currency_code')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label value="Date Format" />
                        <x-text-input wire:model="general.date_format" class="block mt-1 w-full" />
                    </div>
                    <div>
                        <x-input-label value="Time Format" />
                        <select wire:model="general.time_format" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                            <option value="24h">24h</option>
                            <option value="12h">12h</option>
                        </select>
                    </div>
                </div>
                <div class="flex items-center gap-4">
                    <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                        <input type="checkbox" wire:model.live="general.tax_enabled" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 focus:ring-indigo-500">
                        Tax Enabled
                    </label>
                    <div x-show="$wire.general.tax_enabled" class="flex-1">
                        <x-text-input wire:model="general.tax_rate" placeholder="Tax rate %" class="block w-full" />
                        <x-input-error :messages="$errors->get('general.tax_rate')" class="mt-2" />
                    </div>
                </div>
                <div class="flex justify-end">
                    <x-primary-button type="submit">Save</x-primary-button>
                </div>
            </form>
        @endif

        {{-- ============================== STORE ============================== --}}
        @if ($activeSection === 'store')
            <form wire:submit="saveStore" class="space-y-4 max-w-lg">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Store Information</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label value="Opening Time" />
                        <input type="time" wire:model="store.opening_time" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <x-input-error :messages="$errors->get('store.opening_time')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label value="Closing Time" />
                        <input type="time" wire:model="store.closing_time" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <x-input-error :messages="$errors->get('store.closing_time')" class="mt-2" />
                    </div>
                </div>
                <div>
                    <x-input-label value="Receipt Business Info" />
                    <textarea wire:model="store.receipt_business_info" rows="3" placeholder="Printed at the top of every receipt" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500"></textarea>
                </div>
                <div class="flex justify-end">
                    <x-primary-button type="submit">Save</x-primary-button>
                </div>
            </form>
        @endif

        {{-- ============================== SALES ============================== --}}
        @if ($activeSection === 'sales')
            <form wire:submit="saveSales" class="space-y-4 max-w-lg">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Sales Settings</h3>
                <div>
                    <x-input-label value="Default Payment Method" />
                    <select wire:model="sales.default_payment_method_id" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="">None</option>
                        @foreach ($paymentMethods as $method)
                            <option value="{{ $method->id }}">{{ $method->name }}</option>
                        @endforeach
                    </select>
                </div>
                <div>
                    <x-input-label value="Max Discount Percentage" />
                    <x-text-input wire:model="sales.max_discount_percentage" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('sales.max_discount_percentage')" class="mt-2" />
                    <p class="text-xs text-gray-500 dark:text-gray-400 mt-1">Enforced by SaleService on every checkout — a cashier can't apply more than this.</p>
                </div>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="sales.allow_negative_stock_sale" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 focus:ring-indigo-500">
                    Allow selling past available stock
                </label>
                <div class="flex justify-end">
                    <x-primary-button type="submit">Save</x-primary-button>
                </div>
            </form>
        @endif

        {{-- ============================== INVENTORY ============================== --}}
        @if ($activeSection === 'inventory')
            <form wire:submit="saveInventory" class="space-y-4 max-w-lg">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Inventory Settings</h3>
                <div>
                    <x-input-label value="Low Stock Default Threshold" />
                    <x-text-input wire:model="inventory.low_stock_default_threshold" class="block mt-1 w-full" />
                    <x-input-error :messages="$errors->get('inventory.low_stock_default_threshold')" class="mt-2" />
                </div>
                <div class="grid grid-cols-3 gap-4">
                    <div>
                        <x-input-label value="Expiry Alert 1 (days)" />
                        <x-text-input wire:model="inventory.expiry_alert_days_1" class="block mt-1 w-full" />
                    </div>
                    <div>
                        <x-input-label value="Expiry Alert 2 (days)" />
                        <x-text-input wire:model="inventory.expiry_alert_days_2" class="block mt-1 w-full" />
                    </div>
                    <div>
                        <x-input-label value="Expiry Alert 3 (days)" />
                        <x-text-input wire:model="inventory.expiry_alert_days_3" class="block mt-1 w-full" />
                    </div>
                </div>
                <p class="text-xs text-gray-500 dark:text-gray-400">The daily expiry sweep raises a notification for a batch when its days-to-expiry matches any of these three windows.</p>
                <div class="flex justify-end">
                    <x-primary-button type="submit">Save</x-primary-button>
                </div>
            </form>
        @endif

        {{-- ============================== MODULES ============================== --}}
        @if ($activeSection === 'modules')
            <div class="space-y-4 max-w-lg">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Module Management</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Turning a module off hides its sidebar item and blocks its routes for everyone, immediately.</p>
                <div class="divide-y divide-gray-100 dark:divide-gray-700 border border-gray-100 dark:border-gray-700 rounded-md">
                    @foreach ($moduleToggles as $moduleName => $enabled)
                        <div class="flex items-center justify-between px-4 py-3">
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ str($moduleName)->headline() }}</span>
                            <button
                                wire:click="toggleModule('{{ $moduleName }}')"
                                @class(['relative inline-flex h-6 w-11 items-center rounded-full transition', 'bg-indigo-600' => $enabled, 'bg-gray-300 dark:bg-gray-600' => ! $enabled])
                            >
                                <span @class(['inline-block h-4 w-4 transform rounded-full bg-white transition', 'translate-x-6' => $enabled, 'translate-x-1' => ! $enabled])></span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ============================== HARDWARE ============================== --}}
        @if ($activeSection === 'hardware')
            <form wire:submit="saveHardware" class="space-y-4 max-w-lg">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Hardware & Printing</h3>
                <label class="inline-flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="hardware.barcode_scanner_enabled" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 focus:ring-indigo-500">
                    Barcode Scanner Enabled
                </label>
                <label class="flex items-center gap-2 text-sm text-gray-700 dark:text-gray-300">
                    <input type="checkbox" wire:model="hardware.auto_print_receipt" class="rounded border-gray-300 dark:border-gray-700 dark:bg-gray-900 text-indigo-600 focus:ring-indigo-500">
                    Auto-Print Receipt
                </label>
                <div>
                    <x-input-label value="Default Printer" />
                    <x-text-input wire:model="hardware.default_printer_name" class="block mt-1 w-full" />
                </div>
                <div>
                    <x-input-label value="Paper Size" />
                    <select wire:model="hardware.paper_size" class="mt-1 block w-full rounded-md border-gray-300 dark:border-gray-700 dark:bg-gray-900 dark:text-gray-100 shadow-sm focus:border-indigo-500 focus:ring-indigo-500">
                        <option value="58mm">58mm</option>
                        <option value="80mm">80mm</option>
                    </select>
                </div>
                <div class="flex justify-end">
                    <x-primary-button type="submit">Save</x-primary-button>
                </div>
            </form>
        @endif

        {{-- ============================== NOTIFICATIONS ============================== --}}
        @if ($activeSection === 'notifications')
            <div class="space-y-4 max-w-lg">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Notification Settings</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Turns off that category of alert even if the Notifications module overall is enabled.</p>
                <div class="divide-y divide-gray-100 dark:divide-gray-700 border border-gray-100 dark:border-gray-700 rounded-md">
                    @foreach ($notificationToggles as $category => $enabled)
                        <div class="flex items-center justify-between px-4 py-3">
                            <span class="text-sm text-gray-700 dark:text-gray-300">{{ str($category)->headline() }}</span>
                            <button
                                wire:click="toggleNotificationCategory('{{ $category }}')"
                                @class(['relative inline-flex h-6 w-11 items-center rounded-full transition', 'bg-indigo-600' => $enabled, 'bg-gray-300 dark:bg-gray-600' => ! $enabled])
                            >
                                <span @class(['inline-block h-4 w-4 transform rounded-full bg-white transition', 'translate-x-6' => $enabled, 'translate-x-1' => ! $enabled])></span>
                            </button>
                        </div>
                    @endforeach
                </div>
            </div>
        @endif

        {{-- ============================== SECURITY ============================== --}}
        @if ($activeSection === 'security')
            <form wire:submit="saveSecurity" class="space-y-4 max-w-lg">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Security Settings</h3>
                <div class="grid grid-cols-2 gap-4">
                    <div>
                        <x-input-label value="Session Timeout (minutes)" />
                        <x-text-input wire:model="security.session_timeout_minutes" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('security.session_timeout_minutes')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label value="Max Failed Login Attempts" />
                        <x-text-input wire:model="security.max_failed_login_attempts" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('security.max_failed_login_attempts')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label value="Lockout Duration (minutes)" />
                        <x-text-input wire:model="security.lockout_duration_minutes" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('security.lockout_duration_minutes')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label value="Password Min Length" />
                        <x-text-input wire:model="security.password_min_length" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('security.password_min_length')" class="mt-2" />
                    </div>
                    <div>
                        <x-input-label value="Password Reset Token TTL (minutes)" />
                        <x-text-input wire:model="security.password_reset_token_ttl_minutes" class="block mt-1 w-full" />
                        <x-input-error :messages="$errors->get('security.password_reset_token_ttl_minutes')" class="mt-2" />
                    </div>
                </div>
                <div class="flex justify-end">
                    <x-primary-button type="submit">Save</x-primary-button>
                </div>
            </form>
        @endif

        {{-- ============================== BACKUP & RESTORE ============================== --}}
        @if ($activeSection === 'backup')
            <div class="space-y-4">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Backup & Restore</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400 max-w-lg">
                    Running an actual backup (mysqldump, off-host storage) is a server-side operation, not something this page triggers directly —
                    it's handled by the backup/restore/verify scripts documented for deployment. This shows the history those scripts leave behind.
                </p>
                <div class="overflow-x-auto border border-gray-100 dark:border-gray-700 rounded-md">
                    <table class="min-w-full divide-y divide-gray-200 dark:divide-gray-700">
                        <thead class="bg-gray-50 dark:bg-gray-900/40">
                            <tr>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Scope</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Status</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Created</th>
                                <th class="px-4 py-2 text-left text-xs font-medium text-gray-500 dark:text-gray-400 uppercase">Completed</th>
                            </tr>
                        </thead>
                        <tbody class="divide-y divide-gray-200 dark:divide-gray-700">
                            @forelse ($recentBackups as $backup)
                                <tr>
                                    <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ $backup->scope }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-700 dark:text-gray-300">{{ ucfirst($backup->status) }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">{{ $backup->created_at->format('Y-m-d H:i') }}</td>
                                    <td class="px-4 py-2 text-sm text-gray-500 dark:text-gray-400">{{ $backup->completed_at?->format('Y-m-d H:i') ?? '—' }}</td>
                                </tr>
                            @empty
                                <tr><td colspan="4" class="px-4 py-6 text-center text-sm text-gray-500 dark:text-gray-400">No backups recorded yet.</td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        @endif

        {{-- ============================== APPEARANCE ============================== --}}
        @if ($activeSection === 'appearance')
            <div class="space-y-4 max-w-lg">
                <h3 class="font-semibold text-gray-800 dark:text-gray-100">Appearance Settings</h3>
                <p class="text-sm text-gray-500 dark:text-gray-400">Saved to your account — this only affects your own view, and applies immediately.</p>
                <div class="grid grid-cols-3 gap-3">
                    @foreach (['light' => 'Light', 'dark' => 'Dark', 'system' => 'System'] as $value => $label)
                        <button
                            wire:click="saveTheme('{{ $value }}')"
                            @class([
                                'rounded-lg border-2 px-4 py-3 text-sm font-medium text-center transition',
                                'border-indigo-500 text-indigo-700 dark:text-indigo-300' => $theme === $value,
                                'border-gray-200 dark:border-gray-700 text-gray-600 dark:text-gray-300 hover:border-gray-300' => $theme !== $value,
                            ])
                        >
                            {{ $label }}
                        </button>
                    @endforeach
                </div>
            </div>
        @endif
    </div>
</div>
