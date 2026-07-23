<?php

namespace App\Livewire\Settings;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\BackupRecord;
use App\Models\GeneralSetting;
use App\Models\HardwareSetting;
use App\Models\InventorySetting;
use App\Models\ModuleSetting;
use App\Models\NotificationSetting;
use App\Models\PaymentMethod;
use App\Models\SalesSetting;
use App\Models\SecuritySetting;
use App\Models\StoreSetting;
use Livewire\Component;

/**
 * SRS Sec. 20.15: a tree-structured settings nav, one form per section.
 * Every settings table already has an AFTER UPDATE trigger writing its own
 * audit_logs entry (Part E, Section 13e) — none of the save methods below
 * write to audit_logs themselves, only set updated_by so those triggers
 * can attribute the change correctly.
 */
class SettingsManager extends Component
{
    use AuthorizesModuleActions;

    public string $activeSection = 'general';

    /** @var array<string, mixed> */
    public array $general = [];

    /** @var array<string, mixed> */
    public array $store = [];

    /** @var array<string, mixed> */
    public array $sales = [];

    /** @var array<string, mixed> */
    public array $inventory = [];

    /** @var array<string, mixed> */
    public array $hardware = [];

    /** @var array<string, mixed> */
    public array $security = [];

    /** @var array<string, bool> */
    public array $moduleToggles = [];

    /** @var array<string, bool> */
    public array $notificationToggles = [];

    public string $theme = 'system';

    public function mount(): void
    {
        $this->authorizeAction('settings', 'view');
        $this->loadAll();
    }

    private function loadAll(): void
    {
        $g = GeneralSetting::current();
        $this->general = [
            'business_name' => $g->business_name,
            'business_logo_url' => (string) $g->business_logo_url,
            'contact_phone' => (string) $g->contact_phone,
            'contact_email' => (string) $g->contact_email,
            'address' => (string) $g->address,
            'currency_code' => $g->currency_code,
            'date_format' => $g->date_format,
            'time_format' => $g->time_format,
            'tax_enabled' => $g->tax_enabled,
            'tax_rate' => (string) $g->tax_rate,
        ];

        $store = StoreSetting::current();
        $this->store = [
            'opening_time' => (string) $store?->opening_time,
            'closing_time' => (string) $store?->closing_time,
            'receipt_business_info' => (string) $store?->receipt_business_info,
        ];

        $sales = SalesSetting::current();
        $this->sales = [
            'default_payment_method_id' => $sales?->default_payment_method_id,
            'max_discount_percentage' => (string) $sales?->max_discount_percentage,
            'allow_negative_stock_sale' => (bool) $sales?->allow_negative_stock_sale,
        ];

        $inventory = InventorySetting::current();
        $this->inventory = [
            'low_stock_default_threshold' => (string) $inventory?->low_stock_default_threshold,
            'expiry_alert_days_1' => $inventory?->expiry_alert_days_1,
            'expiry_alert_days_2' => $inventory?->expiry_alert_days_2,
            'expiry_alert_days_3' => $inventory?->expiry_alert_days_3,
        ];

        $hardware = HardwareSetting::current();
        $this->hardware = [
            'barcode_scanner_enabled' => (bool) $hardware?->barcode_scanner_enabled,
            'auto_print_receipt' => (bool) $hardware?->auto_print_receipt,
            'default_printer_name' => (string) $hardware?->default_printer_name,
            'paper_size' => $hardware?->paper_size ?? '80mm',
        ];

        $security = SecuritySetting::current();
        $this->security = [
            'session_timeout_minutes' => $security?->session_timeout_minutes,
            'max_failed_login_attempts' => $security?->max_failed_login_attempts,
            'lockout_duration_minutes' => $security?->lockout_duration_minutes,
            'password_min_length' => $security?->password_min_length,
            'password_reset_token_ttl_minutes' => $security?->password_reset_token_ttl_minutes,
        ];

        $this->moduleToggles = ModuleSetting::pluck('is_enabled', 'module_name')->all();
        $this->notificationToggles = NotificationSetting::pluck('is_enabled', 'category')->all();

        $this->theme = auth()->user()->theme ?? 'system';
    }

    public function setSection(string $section): void
    {
        $this->activeSection = $section;
    }

    public function saveGeneral(): void
    {
        $this->authorizeAction('settings', 'update');

        $validated = $this->validate([
            'general.business_name' => ['required', 'string', 'max:150'],
            'general.business_logo_url' => ['nullable', 'string', 'max:255'],
            'general.contact_phone' => ['nullable', 'string', 'max:30'],
            'general.contact_email' => ['nullable', 'email', 'max:150'],
            'general.address' => ['nullable', 'string', 'max:255'],
            'general.currency_code' => ['required', 'string', 'size:3'],
            'general.date_format' => ['required', 'string', 'max:20'],
            'general.time_format' => ['required', 'string', 'max:20'],
            'general.tax_enabled' => ['boolean'],
            'general.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
        ])['general'];

        GeneralSetting::current()->update($validated + ['updated_by' => auth()->id()]);
        $this->flashSaved();
    }

    public function saveStore(): void
    {
        $this->authorizeAction('settings', 'update');

        $validated = $this->validate([
            'store.opening_time' => ['nullable', 'date_format:H:i'],
            'store.closing_time' => ['nullable', 'date_format:H:i'],
            'store.receipt_business_info' => ['nullable', 'string'],
        ])['store'];

        StoreSetting::current()->update([
            ...$validated,
            'opening_time' => $validated['opening_time'] ?: null,
            'closing_time' => $validated['closing_time'] ?: null,
            'updated_by' => auth()->id(),
        ]);
        $this->flashSaved();
    }

    public function saveSales(): void
    {
        $this->authorizeAction('settings', 'update');

        $validated = $this->validate([
            'sales.default_payment_method_id' => ['nullable', 'exists:payment_methods,id'],
            'sales.max_discount_percentage' => ['required', 'numeric', 'min:0', 'max:100'],
            'sales.allow_negative_stock_sale' => ['boolean'],
        ])['sales'];

        SalesSetting::current()->update($validated + ['updated_by' => auth()->id()]);
        $this->flashSaved();
    }

    public function saveInventory(): void
    {
        $this->authorizeAction('settings', 'update');

        $validated = $this->validate([
            'inventory.low_stock_default_threshold' => ['required', 'numeric', 'min:0'],
            'inventory.expiry_alert_days_1' => ['required', 'integer', 'min:0'],
            'inventory.expiry_alert_days_2' => ['required', 'integer', 'min:0'],
            'inventory.expiry_alert_days_3' => ['required', 'integer', 'min:0'],
        ])['inventory'];

        InventorySetting::current()->update($validated + ['updated_by' => auth()->id()]);
        $this->flashSaved();
    }

    public function saveHardware(): void
    {
        $this->authorizeAction('settings', 'update');

        $validated = $this->validate([
            'hardware.barcode_scanner_enabled' => ['boolean'],
            'hardware.auto_print_receipt' => ['boolean'],
            'hardware.default_printer_name' => ['nullable', 'string', 'max:150'],
            'hardware.paper_size' => ['required', 'in:58mm,80mm,A4'],
        ])['hardware'];

        HardwareSetting::current()->update($validated + ['updated_by' => auth()->id()]);
        $this->flashSaved();
    }

    public function saveSecurity(): void
    {
        $this->authorizeAction('settings', 'update');

        $validated = $this->validate([
            'security.session_timeout_minutes' => ['required', 'integer', 'min:1'],
            'security.max_failed_login_attempts' => ['required', 'integer', 'min:1'],
            'security.lockout_duration_minutes' => ['required', 'integer', 'min:1'],
            'security.password_min_length' => ['required', 'integer', 'min:4', 'max:64'],
            'security.password_reset_token_ttl_minutes' => ['required', 'integer', 'min:1'],
        ])['security'];

        SecuritySetting::current()->update($validated + ['updated_by' => auth()->id()]);
        $this->flashSaved();
    }

    public function toggleModule(string $moduleName): void
    {
        $this->authorizeAction('settings', 'update');

        $setting = ModuleSetting::where('module_name', $moduleName)->firstOrFail();
        $setting->update(['is_enabled' => ! $setting->is_enabled, 'updated_by' => auth()->id()]);
        $this->moduleToggles[$moduleName] = $setting->is_enabled;

        $this->flashSaved();
    }

    public function toggleNotificationCategory(string $category): void
    {
        $this->authorizeAction('settings', 'update');

        $setting = NotificationSetting::where('category', $category)->firstOrFail();
        $setting->update(['is_enabled' => ! $setting->is_enabled, 'updated_by' => auth()->id()]);
        $this->notificationToggles[$category] = $setting->is_enabled;

        $this->flashSaved();
    }

    /**
     * Deliberately not gated by settings,update — this is the viewer's own
     * per-user preference (SRS Sec. 20.18), not a system-wide setting, so
     * any authenticated user changes only their own account's theme here.
     */
    public function saveTheme(string $theme): void
    {
        if (! in_array($theme, ['light', 'dark', 'system'], true)) {
            return;
        }

        $this->theme = $theme;
        auth()->user()->update(['theme' => $theme]);

        $this->dispatch('theme-changed', theme: $theme);
    }

    private function flashSaved(): void
    {
        $this->dispatch('flash-message', message: 'Settings saved.', variant: 'success');
    }

    public function render()
    {
        return view('livewire.settings.settings-manager', [
            'paymentMethods' => PaymentMethod::orderBy('name')->get(),
            'recentBackups' => BackupRecord::with('creator')->latest()->limit(10)->get(),
        ]);
    }
}
