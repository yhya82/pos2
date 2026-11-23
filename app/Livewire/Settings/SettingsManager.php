<?php

namespace App\Livewire\Settings;

use App\Events\GeneralSettingChanged;
use App\Events\HardwareSettingChanged;
use App\Events\ModuleSettingChanged;
use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\BackupRecord;
use App\Models\GeneralSetting;
use App\Models\HardwareSetting;
use App\Models\InventorySetting;
use App\Models\LoginSession;
use App\Models\ModuleSetting;
use App\Models\NotificationSetting;
use App\Models\PaymentMethod;
use App\Models\SalesSetting;
use App\Models\SecuritySetting;
use App\Models\StoreSetting;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Livewire\Attributes\On;
use Livewire\Component;
use Livewire\WithFileUploads;

/**
 * SRS Sec. 20.15: a tree-structured settings nav, one form per section.
 * Every settings table already has an AFTER UPDATE trigger writing its own
 * audit_logs entry (Part E, Section 13e) — none of the save methods below
 * write to audit_logs themselves, only set updated_by so those triggers
 * can attribute the change correctly.
 */
class SettingsManager extends Component
{
    use AuthorizesModuleActions, WithFileUploads;

    public string $activeSection = 'general';

    public $logo = null;

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

    /** Which sub-view of the Security Center is showing (overview|policy|devices|activity|threats). */
    public string $securityTab = 'overview';

    /**
     * Devices tab: real-time presence, not just "hasn't timed out yet" —
     * ids of users the 'online-users' presence channel currently reports
     * as connected. The Echo.join() call already runs on every
     * authenticated page (components/layouts/app.blade.php), so these
     * listeners are all that's needed to receive it here too; no second
     * channel join.
     *
     * @var list<int>
     */
    public array $onlineUserIds = [];

    #[On('online-users-synced')]
    public function syncOnlineRoster(array $users): void
    {
        $this->onlineUserIds = collect($users)->pluck('id')->all();
    }

    #[On('online-user-joined')]
    public function onlineUserJoined(array $user): void
    {
        $this->onlineUserIds = collect($this->onlineUserIds)->push($user['id'])->unique()->values()->all();
    }

    #[On('online-user-left')]
    public function onlineUserLeft(array $user): void
    {
        $this->onlineUserIds = collect($this->onlineUserIds)->reject(fn ($id) => $id === $user['id'])->values()->all();
    }

    /** @var array<string, bool> */
    public array $moduleToggles = [];

    /** @var array<string, bool> */
    public array $notificationToggles = [];

    public string $theme = 'system';

    /** The store phone on file when it isn't +220 + 9 digits (an old or empty one) — a hint while editing. */
    public string $legacyContactPhone = '';

    public bool $contactPhoneNeedsUpdate = false;

    public function mount(): void
    {
        $this->authorizeAction('settings', 'view');
        $this->loadAll();
    }

    private function loadAll(): void
    {
        $g = GeneralSetting::current();
        $this->contactPhoneNeedsUpdate = ! $g->hasValidContactPhone();
        $this->legacyContactPhone = $g->hasValidContactPhone() ? '' : (string) $g->contact_phone;
        $this->general = [
            'business_name' => $g->business_name,
            'business_logo_url' => (string) $g->business_logo_url,
            // The box holds the 9 digits; +220 is added on save.
            'contact_phone' => $g->hasValidContactPhone() ? substr($g->contact_phone, 4) : '',
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

        $this->loadSecurityFromDb();

        $this->moduleToggles = ModuleSetting::pluck('is_enabled', 'module_name')->all();
        $this->notificationToggles = NotificationSetting::pluck('is_enabled', 'category')->all();

        $this->theme = auth()->user()->theme ?? 'system';
    }

    public function setSection(string $section): void
    {
        $this->activeSection = $section;
    }

    /**
     * Devices/Login activity/Threats show every user's sessions and
     * security events, not just the viewer's own — the same sensitivity
     * Audit Logs already restricts to Administrator, so these three stay
     * Administrator-only even for someone who otherwise has
     * settings,update. A non-admin silently stays on Overview/Policy.
     */
    public function setSecurityTab(string $tab): void
    {
        $allowed = $this->canSeeSecurityHistory()
            ? ['overview', 'policy', 'devices', 'activity', 'threats', 'people']
            : ['overview', 'policy'];

        if (! in_array($tab, $allowed, true)) {
            return;
        }

        $this->securityTab = $tab;
    }

    /**
     * Devices/Login activity/Threats/People show every user's sessions
     * and security events, not just the viewer's own — the same data
     * Audit Logs gates behind both the Administrator role AND the
     * audit_logs,view permission. Checking only isAdministrator() here
     * (as these tabs did before) would let someone see this data from
     * Settings even if their Administrator role had that permission
     * specifically revoked from the Roles screen — the two pages must
     * agree on who's allowed to see it.
     */
    private function canSeeSecurityHistory(): bool
    {
        return auth()->user()->isAdministrator() && auth()->user()->hasPermission('audit_logs', 'view');
    }

    /** @var array<string, mixed> the real values, fresh from the database. */
    private function loadSecurityFromDb(): void
    {
        $security = SecuritySetting::current();

        $this->security = [
            'session_timeout_minutes' => $security?->session_timeout_minutes,
            'max_failed_login_attempts' => $security?->max_failed_login_attempts,
            'lockout_duration_minutes' => $security?->lockout_duration_minutes,
            'password_min_length' => $security?->password_min_length,
            'password_history_count' => $security?->password_history_count,
            'password_expiry_days' => $security?->password_expiry_days,
            'password_require_uppercase' => (bool) $security?->password_require_uppercase,
            'password_require_lowercase' => (bool) $security?->password_require_lowercase,
            'password_require_number' => (bool) $security?->password_require_number,
            'password_require_symbol' => (bool) $security?->password_require_symbol,
        ];
    }

    /**
     * Opens the Password Policy modal — reloaded fresh from the database
     * every time, so an edit abandoned by clicking the backdrop or
     * pressing Escape (neither of which calls closePolicyEditor()) never
     * leaks into the next time the modal is opened.
     */
    public function openPolicyEditor(): void
    {
        $this->authorizeAction('settings', 'update');

        $this->loadSecurityFromDb();
        $this->resetValidation();

        $this->dispatch('open-modal', 'password-policy-form');
    }

    public function closePolicyEditor(): void
    {
        $this->loadSecurityFromDb();
        $this->resetValidation();

        $this->dispatch('close-modal', 'password-policy-form');
    }

    /** The Overview tab's "Edit Password Policy" button: jumps to the Policy tab and opens the modal in one step. */
    public function editPasswordPolicy(): void
    {
        $this->setSecurityTab('policy');
        $this->openPolicyEditor();
    }

    /**
     * Devices tab: signs a specific session out. Gated the same as the
     * tab itself — and never the session doing the revoking, so an admin
     * can't accidentally lock themselves out from this screen (they can
     * always log out normally instead).
     */
    public function revokeDevice(int $sessionId): void
    {
        $this->authorizeAction('settings', 'update');
        abort_unless($this->canSeeSecurityHistory(), 403);

        $session = LoginSession::findOrFail($sessionId);

        if ($session->token_hash === hash('sha256', session()->getId())) {
            $this->dispatch('flash-message', message: "That's your current session — log out instead of revoking it here.", variant: 'error');

            return;
        }

        $session->update(['revoked_at' => now()]);

        AuditLog::security('session_revoked', $session->user_id, ['revoked_by' => auth()->id()], 'User');

        $this->dispatch('flash-message', message: 'Session revoked.', variant: 'success');
    }

    public function saveGeneral(): void
    {
        $this->authorizeAction('settings', 'update');

        $validated = $this->validate([
            'general.business_name' => ['required', 'string', 'max:150'],
            'general.contact_phone' => ['required', 'digits:9'],
            'general.contact_email' => ['nullable', 'email', 'max:150'],
            'general.address' => ['nullable', 'string', 'max:255'],
            'general.currency_code' => ['required', 'string', 'size:3'],
            'general.date_format' => ['required', 'string', 'max:20'],
            'general.time_format' => ['required', 'string', 'max:20'],
            'general.tax_enabled' => ['boolean'],
            'general.tax_rate' => ['required', 'numeric', 'min:0', 'max:100'],
            'logo' => ['nullable', 'image', 'max:2048'],
        ], [
            'general.contact_phone.required' => 'A store phone number is required (9 digits — the +220 prefix is added automatically).',
            'general.contact_phone.digits' => 'Phone number must be exactly 9 digits (the +220 prefix is added automatically).',
        ])['general'];

        $validated['contact_phone'] = '+220'.$validated['contact_phone'];

        $general = GeneralSetting::current();
        $logoPath = $general->business_logo_url;

        if ($this->logo) {
            if ($logoPath) {
                Storage::disk('public')->delete($logoPath);
            }
            $logoPath = $this->logo->store('logos', 'public');
        }

        $general->update($validated + ['business_logo_url' => $logoPath, 'updated_by' => auth()->id()]);

        $this->logo = null;
        $this->general['business_logo_url'] = (string) $logoPath;
        $this->contactPhoneNeedsUpdate = false;
        $this->legacyContactPhone = '';

        event(new GeneralSettingChanged);

        $this->flashSaved();
    }

    public function removeLogo(): void
    {
        $this->authorizeAction('settings', 'update');

        $general = GeneralSetting::current();

        if ($general->business_logo_url) {
            Storage::disk('public')->delete($general->business_logo_url);
        }

        $general->update(['business_logo_url' => null, 'updated_by' => auth()->id()]);

        $this->logo = null;
        $this->general['business_logo_url'] = '';

        event(new GeneralSettingChanged);

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
        ])['sales'];

        SalesSetting::current()->update($validated + ['updated_by' => auth()->id()]);
        $this->flashSaved();
    }

    public function saveInventory(): void
    {
        $this->authorizeAction('settings', 'update');

        $validated = $this->validate([
            'inventory.low_stock_default_threshold' => ['required', new \App\Rules\WholeNumber(0)],
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

        event(new HardwareSettingChanged);

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
            'security.password_history_count' => ['required', 'integer', 'min:0', 'max:50'],
            'security.password_expiry_days' => ['required', 'integer', 'min:0', 'max:3650'],
            'security.password_require_uppercase' => ['boolean'],
            'security.password_require_lowercase' => ['boolean'],
            'security.password_require_number' => ['boolean'],
            'security.password_require_symbol' => ['boolean'],
        ])['security'];

        SecuritySetting::current()->update($validated + ['updated_by' => auth()->id()]);
        $this->dispatch('close-modal', 'password-policy-form');
        $this->flashSaved();
    }

    /**
     * A 0-100 read of how strict the Security form's CURRENT values are
     * (including unsaved edits, since every field it reads is bound
     * live) — not a measure of real login activity, which is what the
     * Audit Logs alert bar is for. Weights are split evenly so no single
     * field dominates the number: lockout enabled (15), lockout duration
     * (10), minimum length (up to 15, half credit under 12 chars),
     * complexity requirements all on (15), reuse history on (15),
     * expiry on (15), session timeout sane (15) = 100.
     */
    private function securityScore(): array
    {
        $s = $this->security;

        $score = 0;

        $maxAttempts = (int) ($s['max_failed_login_attempts'] ?? 0);
        if ($maxAttempts >= 1 && $maxAttempts <= 10) {
            $score += 15;
        }

        if ((int) ($s['lockout_duration_minutes'] ?? 0) >= 15) {
            $score += 10;
        }

        $minLength = (int) ($s['password_min_length'] ?? 0);
        if ($minLength >= 12) {
            $score += 15;
        } elseif ($minLength >= 8) {
            $score += 8;
        }

        if (($s['password_require_uppercase'] ?? false) && ($s['password_require_lowercase'] ?? false)
            && ($s['password_require_number'] ?? false) && ($s['password_require_symbol'] ?? false)) {
            $score += 15;
        }

        if ((int) ($s['password_history_count'] ?? 0) >= 3) {
            $score += 15;
        }

        $expiry = (int) ($s['password_expiry_days'] ?? 0);
        if ($expiry >= 1 && $expiry <= 180) {
            $score += 15;
        }

        $timeout = (int) ($s['session_timeout_minutes'] ?? 0);
        if ($timeout >= 1 && $timeout <= 60) {
            $score += 15;
        }

        $score = min(100, $score);

        return [
            'score' => $score,
            'label' => match (true) {
                $score >= 70 => 'Strong',
                $score >= 40 => 'Fair',
                default => 'Weak',
            },
            'tone' => match (true) {
                $score >= 70 => 'ok',
                $score >= 40 => 'warn',
                default => 'acc',
            },
        ];
    }

    /**
     * The same scoring idea as securityScore(), narrowed to just the
     * password-rule criteria (length, complexity, history, expiry — 60 of
     * the full score's 100 points), scaled back up to /100 so the same
     * Weak/Fair/Strong bands apply. Shown on the score card as "Password
     * policy strength," separately from the all-settings score.
     */
    private function passwordPolicyStrength(): array
    {
        $s = $this->security;

        $score = 0;

        $minLength = (int) ($s['password_min_length'] ?? 0);
        if ($minLength >= 12) {
            $score += 15;
        } elseif ($minLength >= 8) {
            $score += 8;
        }

        if (($s['password_require_uppercase'] ?? false) && ($s['password_require_lowercase'] ?? false)
            && ($s['password_require_number'] ?? false) && ($s['password_require_symbol'] ?? false)) {
            $score += 15;
        }

        if ((int) ($s['password_history_count'] ?? 0) >= 3) {
            $score += 15;
        }

        $expiry = (int) ($s['password_expiry_days'] ?? 0);
        if ($expiry >= 1 && $expiry <= 180) {
            $score += 15;
        }

        $scaled = (int) round(($score / 60) * 100);

        return [
            'label' => match (true) {
                $scaled >= 70 => 'Strong',
                $scaled >= 40 => 'Fair',
                default => 'Weak',
            },
            'tone' => match (true) {
                $scaled >= 70 => 'ok',
                $scaled >= 40 => 'warn',
                default => 'acc',
            },
        ];
    }

    /**
     * Every suggestion maps to one of securityScore()'s real criteria —
     * nothing here is invented or decorative. Deliberately excludes a
     * breach-password check ("have your passwords been pwned") since that
     * would need a new external API integration this app doesn't have.
     *
     * @return list<string>
     */
    private function securitySuggestions(): array
    {
        $s = $this->security;
        $tips = [];

        if ((int) ($s['password_min_length'] ?? 0) < 12) {
            $tips[] = 'Raise the minimum password length to 12 or more.';
        }

        if (! ($s['password_require_symbol'] ?? false)) {
            $tips[] = 'Require at least one symbol.';
        }

        $expiry = (int) ($s['password_expiry_days'] ?? 0);
        if ($expiry === 0) {
            $tips[] = 'Turn on password expiry (180 days or fewer).';
        } elseif ($expiry > 180) {
            $tips[] = 'Lower password expiry to 180 days or fewer.';
        }

        if ((int) ($s['password_history_count'] ?? 0) < 3) {
            $tips[] = 'Remember at least 3 of the last passwords.';
        }

        $timeout = (int) ($s['session_timeout_minutes'] ?? 0);
        if ($timeout === 0 || $timeout > 60) {
            $tips[] = 'Sign idle users out after 60 minutes or less.';
        }

        $attempts = (int) ($s['max_failed_login_attempts'] ?? 0);
        if ($attempts === 0 || $attempts > 10) {
            $tips[] = 'Lower the failed-login lockout threshold to 10 attempts or fewer.';
        }

        if ((int) ($s['lockout_duration_minutes'] ?? 0) < 15) {
            $tips[] = 'Increase the lockout duration to at least 15 minutes.';
        }

        return $tips;
    }

    /**
     * Overview tab stat tiles. "Failed logins" and "new-device logins" use
     * the same rolling 24h window AuditLogViewer's alert bar uses, so the
     * same number means the same thing in both places. Expiry counts are
     * 0 whenever password_expiry_days is off (0), same as
     * User::passwordHasExpired()'s own rule.
     *
     * @return array{failed_logins: int, locked: int, devices: int, new_devices: int, expiring_soon: int, expired: int}
     */
    private function securityOverviewStats(): array
    {
        $counts = AuditLog::securityCounts(now()->subDay());

        $expiryDays = (int) SecuritySetting::current()->password_expiry_days;
        $expiringSoon = 0;
        $expired = 0;

        if ($expiryDays > 0) {
            $soonCutoff = now()->addDays(7);

            User::whereNotNull('password_changed_at')->get(['password_changed_at'])->each(function ($user) use ($expiryDays, $soonCutoff, &$expiringSoon, &$expired) {
                $expiresAt = $user->password_changed_at->copy()->addDays($expiryDays);

                if ($expiresAt->isPast()) {
                    $expired++;
                } elseif ($expiresAt->lessThanOrEqualTo($soonCutoff)) {
                    $expiringSoon++;
                }
            });
        }

        return [
            'failed_logins' => $counts['failed_logins'],
            'locked' => User::where('locked_until', '>', now())->count(),
            'devices' => LoginSession::whereNull('revoked_at')->where('expires_at', '>', now())->count(),
            'new_devices' => $counts['new_devices'],
            'expiring_soon' => $expiringSoon,
            'expired' => $expired,
        ];
    }

    /** Devices tab: every session still active anywhere, newest first. */
    private function securityDevices()
    {
        return LoginSession::with('user')
            ->whereNull('revoked_at')
            ->where('expires_at', '>', now())
            ->orderByDesc('created_at')
            ->get();
    }

    /** Login activity tab: the most recent security events, of any kind. */
    private function securityLoginActivity()
    {
        return AuditLog::with('user')
            ->where('module', 'security')
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    /**
     * Threats tab: the same security events, narrowed to the curated,
     * higher-severity subset (AuditLog::THREAT_ACTIONS) — the same list
     * that drives this tab's row coloring, so a tampering/lockout/etc.
     * entry is never defined one way here and another way in the Login
     * activity tab or the real Audit Logs page.
     */
    private function securityThreats()
    {
        return AuditLog::with('user')
            ->where('module', 'security')
            ->whereIn('action', AuditLog::THREAT_ACTIONS)
            ->orderByDesc('created_at')
            ->limit(50)
            ->get();
    }

    /**
     * People tab: the security-relevant slice of who's been triggering
     * these events over the last 7 days — failed logins, denials,
     * tampering, new-device flags, blocked password reuse, and lockouts,
     * plus whether they're locked RIGHT NOW. Deliberately excludes the
     * general business-activity counts (sales, voids, refunds) Audit
     * Logs' own "Who did what" tab shows — those aren't a security
     * concern, so they stay there, not duplicated here.
     */
    private function securityPeople()
    {
        $since = now()->subDays(6)->toDateString();

        return DB::table('audit_logs as a')
            ->leftJoin('users as u', 'u.id', '=', 'a.user_id')
            ->leftJoin('roles as r', 'r.id', '=', 'u.role_id')
            ->where('a.module', 'security')
            ->whereDate('a.created_at', '>=', $since)
            ->selectRaw("a.user_id,
                COALESCE(u.name, 'No account / system') AS name,
                r.name AS role,
                u.locked_until,
                SUM(a.action IN ('login_failed','login_blocked')) AS failed_logins,
                SUM(a.action = 'access_denied') AS denied,
                SUM(a.action = 'tamper_ignored') AS tampering,
                SUM(a.action = 'login_new_device') AS new_devices,
                SUM(a.action = 'password_reused_blocked') AS reuse_blocked,
                SUM(a.action = 'account_locked') AS lockouts,
                MAX(a.created_at) AS last_seen")
            ->groupBy('a.user_id', 'u.name', 'r.name', 'u.locked_until')
            ->orderByRaw("(SUM(a.action = 'tamper_ignored') * 100
                + SUM(a.action = 'access_denied') * 10
                + SUM(a.action = 'account_locked') * 20
                + SUM(a.action IN ('login_failed','login_blocked'))
                + SUM(a.action = 'login_new_device') * 2
                + SUM(a.action = 'password_reused_blocked') * 2) DESC")
            ->limit(50)
            ->get();
    }

    public function toggleModule(string $moduleName): void
    {
        $this->authorizeAction('settings', 'update');

        $setting = ModuleSetting::where('module_name', $moduleName)->firstOrFail();
        $setting->update(['is_enabled' => ! $setting->is_enabled, 'updated_by' => auth()->id()]);
        $this->moduleToggles[$moduleName] = $setting->is_enabled;

        event(new ModuleSettingChanged($setting->module_name, $setting->is_enabled));

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
        $isSecurity = $this->activeSection === 'security';
        $isAdmin = $isSecurity && $this->canSeeSecurityHistory();

        return view('livewire.settings.settings-manager', [
            'paymentMethods' => PaymentMethod::orderBy('name')->get(),
            'recentBackups' => BackupRecord::with('creator')->latest()->limit(10)->get(),
            'generalSettings' => GeneralSetting::current(),
            'securityScore' => $isSecurity ? $this->securityScore() : null,
            'securityPasswordStrength' => $isSecurity ? $this->passwordPolicyStrength() : null,
            'securitySuggestions' => $isSecurity ? $this->securitySuggestions() : null,
            'securityStats' => $isSecurity ? $this->securityOverviewStats() : null,
            'securityDevices' => ($isAdmin && $this->securityTab === 'devices') ? $this->securityDevices() : null,
            'securityActivity' => ($isAdmin && $this->securityTab === 'activity') ? $this->securityLoginActivity() : null,
            'securityThreats' => $isAdmin ? $this->securityThreats() : null,
            'securityPeople' => ($isAdmin && $this->securityTab === 'people') ? $this->securityPeople() : null,
        ]);
    }
}
