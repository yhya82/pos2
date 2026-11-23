<?php

namespace Tests\Feature;

use App\Livewire\Settings\SettingsManager;
use App\Models\AuditLog;
use App\Models\LoginSession;
use App\Models\Permission;
use App\Models\Role;
use App\Models\SecuritySetting;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Settings > Security's Overview/Policy/Devices/Login activity/Threats
 * tabs — Devices, Login activity and Threats show every user's sessions
 * and security events, so they stay Administrator-only like Audit Logs
 * already is, even for a non-admin who holds settings,update.
 */
class SecurityCenterTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private User $settingsEditor;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();

        $role = Role::create(['name' => 'Settings Editor', 'description' => 'settings only', 'status' => 'active']);
        $role->permissions()->attach(Permission::where('module', 'settings')->pluck('id'));
        $this->settingsEditor = User::factory()->create(['role_id' => $role->id]);
    }

    public function test_a_non_administrator_cannot_switch_to_devices_activity_or_threats(): void
    {
        $component = Livewire::actingAs($this->settingsEditor)->test(SettingsManager::class)
            ->set('activeSection', 'security');

        $component->call('setSecurityTab', 'devices');
        $this->assertSame('overview', $component->get('securityTab'));

        $component->call('setSecurityTab', 'activity');
        $this->assertSame('overview', $component->get('securityTab'));

        $component->call('setSecurityTab', 'threats');
        $this->assertSame('overview', $component->get('securityTab'));

        $component->call('setSecurityTab', 'policy')
            ->assertSet('securityTab', 'policy');
    }

    public function test_an_administrator_can_switch_to_every_tab(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'devices')->assertSet('securityTab', 'devices')
            ->call('setSecurityTab', 'activity')->assertSet('securityTab', 'activity')
            ->call('setSecurityTab', 'threats')->assertSet('securityTab', 'threats');
    }

    public function test_opening_the_policy_editor_dispatches_the_modal_open_event(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'policy')
            ->call('openPolicyEditor')
            ->assertDispatched('open-modal', 'password-policy-form');
    }

    public function test_cancelling_the_policy_editor_discards_unsaved_edits_and_closes_the_modal(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'policy')
            ->call('openPolicyEditor')
            ->set('security.password_min_length', 40)
            ->call('closePolicyEditor')
            ->assertDispatched('close-modal', 'password-policy-form')
            ->assertSet('security.password_min_length', SecuritySetting::current()->password_min_length);
    }

    public function test_saving_the_policy_closes_the_modal(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'policy')
            ->call('openPolicyEditor')
            ->set('security.session_timeout_minutes', 45)
            ->set('security.max_failed_login_attempts', 5)
            ->set('security.lockout_duration_minutes', 15)
            ->set('security.password_min_length', 10)
            ->set('security.password_history_count', 5)
            ->set('security.password_expiry_days', 90)
            ->call('saveSecurity')
            ->assertHasNoErrors()
            ->assertDispatched('close-modal', 'password-policy-form');

        $this->assertSame(10, SecuritySetting::current()->password_min_length);
    }

    public function test_devices_tab_reflects_real_time_presence_not_just_an_unexpired_session(): void
    {
        $other = User::factory()->create();
        LoginSession::startFor($other, 'someone-elses-session', '10.0.0.2', 'Other device');

        $component = Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'devices')
            ->assertSet('onlineUserIds', []);

        $component->dispatch('online-users-synced', users: [
            ['id' => $other->id, 'name' => $other->name, 'role' => null],
        ]);
        $this->assertSame([$other->id], $component->get('onlineUserIds'));

        $component->dispatch('online-user-left', user: ['id' => $other->id, 'name' => $other->name, 'role' => null]);
        $this->assertSame([], $component->get('onlineUserIds'));
    }

    public function test_devices_tab_lists_active_sessions_and_hides_revoke_on_the_current_one(): void
    {
        LoginSession::startFor($this->admin, session()->getId(), '10.0.0.1', 'PHPUnit Current');

        $other = User::factory()->create();
        LoginSession::startFor($other, 'someone-elses-session', '10.0.0.2', 'Other device');

        $devices = Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'devices')
            ->viewData('securityDevices');

        $this->assertCount(2, $devices);
    }

    public function test_your_own_session_always_shows_online_even_before_the_presence_sync_lands(): void
    {
        LoginSession::startFor($this->admin, session()->getId(), '10.0.0.1', 'PHPUnit Current');

        // No 'online-users-synced' dispatch yet — onlineUserIds is still [].
        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'devices')
            ->assertSet('onlineUserIds', [])
            ->assertSeeHtml('status ok')
            ->assertSee('Online now')
            ->assertDontSee('Away');
    }

    public function test_someone_elses_session_shows_away_with_a_styled_badge_until_they_are_reported_online(): void
    {
        $other = User::factory()->create();
        LoginSession::startFor($other, 'someone-elses-session', '10.0.0.2', 'Other device');

        $component = Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'devices')
            ->assertSeeHtml('status plain')
            ->assertSee('Away');

        $component->dispatch('online-users-synced', users: [
            ['id' => $other->id, 'name' => $other->name, 'role' => null],
        ]);

        $component->assertSee('Online now')->assertDontSee('Away');
    }

    public function test_revoking_a_device_signs_it_out_and_is_logged(): void
    {
        $other = User::factory()->create();
        $otherSession = LoginSession::startFor($other, 'someone-elses-session', '10.0.0.2', 'Other device');

        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'devices')
            ->call('revokeDevice', $otherSession->id);

        $this->assertNotNull($otherSession->fresh()->revoked_at);
        $this->assertTrue(
            AuditLog::where('module', 'security')->where('action', 'session_revoked')->where('user_id', $other->id)->exists()
        );
    }

    public function test_an_admin_cannot_revoke_their_own_current_session_from_this_screen(): void
    {
        $session = LoginSession::startFor($this->admin, session()->getId(), '10.0.0.1', 'PHPUnit Current');

        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'devices')
            ->call('revokeDevice', $session->id);

        $this->assertNull($session->fresh()->revoked_at);
    }

    public function test_overview_stats_count_locked_accounts_and_active_sessions(): void
    {
        User::factory()->create(['locked_until' => now()->addMinutes(10)]);
        LoginSession::startFor($this->admin, session()->getId(), '10.0.0.1', 'PHPUnit');

        $stats = Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->viewData('securityStats');

        $this->assertSame(1, $stats['locked']);
        $this->assertSame(1, $stats['devices']);
    }

    public function test_overview_stats_count_total_successful_and_failed_logins(): void
    {
        AuditLog::security('login', $this->admin->id, ['email' => $this->admin->email], 'User');
        AuditLog::security('login', $this->admin->id, ['email' => $this->admin->email], 'User');
        AuditLog::security('login_failed', null, ['email' => 'nobody@example.com'], 'User');

        $stats = Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->viewData('securityStats');

        $this->assertSame(2, $stats['successful_logins']);
        $this->assertSame(1, $stats['failed_logins']);
        $this->assertSame(3, $stats['total_logins']);
    }

    public function test_threats_tab_only_shows_the_curated_higher_severity_actions(): void
    {
        AuditLog::security('login', $this->admin->id, ['email' => $this->admin->email], 'User');
        AuditLog::security('logout', $this->admin->id, null, 'User');
        AuditLog::security('account_locked', $this->admin->id, [], 'User');
        AuditLog::security('access_denied', null, ['path' => '/x'], 'Request');

        $threats = Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'threats')
            ->viewData('securityThreats');

        $actions = $threats->pluck('action')->all();

        $this->assertContains('account_locked', $actions);
        $this->assertContains('access_denied', $actions);
        $this->assertNotContains('login', $actions);
        $this->assertNotContains('logout', $actions);
    }

    public function test_a_blocked_password_reuse_is_colored_the_same_warn_severity_everywhere(): void
    {
        AuditLog::security('password_reused_blocked', $this->admin->id, ['email' => $this->admin->email], 'User');

        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'threats')
            ->assertSeeHtml('threatrow warn');
    }

    public function test_an_administrator_whose_audit_logs_permission_was_revoked_loses_the_sensitive_tabs_too(): void
    {
        $administratorRole = Role::where('name', 'Administrator')->firstOrFail();
        $administratorRole->permissions()->detach(
            Permission::where('module', 'audit_logs')->where('action', 'view')->value('id')
        );

        $strippedAdmin = User::factory()->create(['role_id' => $administratorRole->id]);

        $component = Livewire::actingAs($strippedAdmin)->test(SettingsManager::class)
            ->set('activeSection', 'security');

        $component->call('setSecurityTab', 'devices');
        $this->assertSame('overview', $component->get('securityTab'));

        $component->call('setSecurityTab', 'people');
        $this->assertSame('overview', $component->get('securityTab'));
    }

    public function test_people_tab_shows_the_security_relevant_counts_per_person_and_flags_risky_ones(): void
    {
        $other = User::factory()->create();

        AuditLog::security('login_failed', $other->id, ['email' => $other->email], 'User');
        AuditLog::security('access_denied', $other->id, ['path' => '/x'], 'Request');
        AuditLog::security('access_denied', $other->id, ['path' => '/y'], 'Request');
        AuditLog::security('access_denied', $other->id, ['path' => '/z'], 'Request');

        $people = Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'people')
            ->viewData('securityPeople');

        $row = collect($people)->firstWhere('user_id', $other->id);

        $this->assertNotNull($row);
        $this->assertSame(1, (int) $row->failed_logins);
        $this->assertSame(3, (int) $row->denied);

        // Business-activity counts (sales/voids/refunds) are deliberately
        // not part of this security-focused query — they stay on Audit
        // Logs' own "Who did what" tab instead of being duplicated here.
        $this->assertFalse(isset($row->sales));
        $this->assertFalse(isset($row->voids));
        $this->assertFalse(isset($row->refunds));
    }

    public function test_the_suggestions_list_reacts_to_the_current_unsaved_form_values(): void
    {
        $component = Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('activeSection', 'security')
            ->call('setSecurityTab', 'policy')
            ->call('openPolicyEditor')
            ->set('security.password_min_length', 6)
            ->set('security.password_require_symbol', false)
            ->set('security.password_expiry_days', 0);

        $tips = $component->viewData('securitySuggestions');

        $this->assertContains('Raise the minimum password length to 12 or more.', $tips);
        $this->assertContains('Require at least one symbol.', $tips);
        $this->assertContains('Turn on password expiry (180 days or fewer).', $tips);
    }
}
