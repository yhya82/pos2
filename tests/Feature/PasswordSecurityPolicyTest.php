<?php

namespace Tests\Feature;

use App\Models\AuditLog;
use App\Models\LoginSession;
use App\Models\SecuritySetting;
use App\Models\SystemNotification;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The password policy (length + required character classes + reuse
 * history), password expiry forcing a change at login, force-logout of
 * other sessions on any real password change, and flagging a login from
 * an IP never seen before for that user.
 */
class PasswordSecurityPolicyTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_a_new_password_missing_a_character_class_is_refused_in_plain_english(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'alllowercase1')
            ->set('password_confirmation', 'alllowercase1')
            ->call('updatePassword')
            ->assertHasErrors('password')
            ->assertSee('Password must include an uppercase letter.');
    }

    public function test_a_password_missing_a_symbol_is_refused(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'Password123')
            ->set('password_confirmation', 'Password123')
            ->call('updatePassword')
            ->assertHasErrors('password')
            ->assertSee('Password must include a symbol');
    }

    public function test_a_compliant_password_is_accepted_and_changes_the_hash(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'Correct123!')
            ->set('password_confirmation', 'Correct123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('Correct123!', $user->refresh()->password_hash));
        $this->assertNotNull($user->password_changed_at);
    }

    public function test_reusing_one_of_the_last_n_passwords_is_blocked(): void
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'FirstOne123!')
            ->set('password_confirmation', 'FirstOne123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        // "Changing" to the exact password just set — it's the most
        // recent entry in history, so it's a reuse like any other.
        Volt::test('profile.update-password-form')
            ->set('current_password', 'FirstOne123!')
            ->set('password', 'FirstOne123!')
            ->set('password_confirmation', 'FirstOne123!')
            ->call('updatePassword')
            ->assertHasErrors('password');

        $this->assertTrue($this->security('password_reused_blocked')->where('user_id', $user->id)->exists());
    }

    public function test_history_check_is_off_when_the_setting_is_zero(): void
    {
        SecuritySetting::current()->update(['password_history_count' => 0]);

        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'FirstOne123!')
            ->set('password_confirmation', 'FirstOne123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        // Changing straight back is allowed once the rule is disabled.
        Volt::test('profile.update-password-form')
            ->set('current_password', 'FirstOne123!')
            ->set('password', 'BackAgain123!')
            ->set('password_confirmation', 'BackAgain123!')
            ->call('updatePassword')
            ->assertHasNoErrors();
    }

    public function test_changing_your_own_password_does_not_sign_you_out_of_the_session_you_used(): void
    {
        $user = User::factory()->create();
        LoginSession::startFor($user, session()->getId(), '127.0.0.1', 'PHPUnit');
        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'Correct123!')
            ->set('password_confirmation', 'Correct123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertNotNull(LoginSession::findActiveBySessionId(session()->getId()));
    }

    public function test_changing_your_password_revokes_your_other_sessions(): void
    {
        $user = User::factory()->create();
        LoginSession::startFor($user, 'some-other-device-session', '10.0.0.5', 'Other device');
        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('current_password', 'password')
            ->set('password', 'Correct123!')
            ->set('password_confirmation', 'Correct123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertNull(LoginSession::findActiveBySessionId('some-other-device-session'));
    }

    public function test_an_admin_resetting_someone_elses_password_revokes_every_one_of_their_sessions(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();
        LoginSession::startFor($target, 'targets-own-session', '10.0.0.6', 'Target device');

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->set('editingUserId', $target->id)
            ->set('name', $target->name)
            ->set('username', $target->username)
            ->set('phone', '831234567')
            ->set('roleId', $target->role_id)
            ->set('status', 'active')
            ->set('password', 'ResetByAdmin123!')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNull(LoginSession::findActiveBySessionId('targets-own-session'));
        $this->assertTrue(Hash::check('ResetByAdmin123!', $target->refresh()->password_hash));
    }

    public function test_the_users_index_change_password_action_enforces_the_policy_and_revokes_sessions(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();
        LoginSession::startFor($target, 'targets-own-session', '10.0.0.6', 'Target device');

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('changePassword', $target->id)
            ->assertDispatched('open-modal', 'change-password')
            ->set('newPassword', 'alllowercase')
            ->call('savePassword')
            ->assertHasErrors('newPassword');

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('changePassword', $target->id)
            ->set('newPassword', 'ResetFromIndex123!')
            ->call('savePassword')
            ->assertHasNoErrors()
            ->assertDispatched('close-modal', 'change-password');

        $this->assertTrue(Hash::check('ResetFromIndex123!', $target->refresh()->password_hash));
        $this->assertNull(LoginSession::findActiveBySessionId('targets-own-session'));
    }

    public function test_resetting_someone_elses_password_from_the_index_never_asks_for_a_current_password(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('changePassword', $target->id)
            ->assertDontSee('Current Password')
            ->set('newPassword', 'ResetFromIndex123!')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('ResetFromIndex123!', $target->refresh()->password_hash));
    }

    public function test_changing_your_own_password_from_the_index_requires_the_current_one_and_keeps_this_session(): void
    {
        $admin = User::factory()->create();
        LoginSession::startFor($admin, session()->getId(), '10.0.0.1', 'PHPUnit');

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('changePassword', $admin->id)
            ->assertSee('Current Password')
            ->set('currentPassword', 'wrong-password')
            ->set('newPassword', 'SelfChange123!')
            ->call('savePassword')
            ->assertHasErrors('currentPassword');

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('changePassword', $admin->id)
            ->set('currentPassword', 'password')
            ->set('newPassword', 'SelfChange123!')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('SelfChange123!', $admin->refresh()->password_hash));
        $this->assertNotNull(LoginSession::findActiveBySessionId(session()->getId()));
    }

    public function test_the_policy_hint_only_mentions_currently_required_character_classes(): void
    {
        SecuritySetting::current()->update([
            'password_require_uppercase' => true,
            'password_require_lowercase' => false,
            'password_require_number' => false,
            'password_require_symbol' => false,
            'password_history_count' => 0,
        ]);

        $admin = User::factory()->create();
        $target = User::factory()->create();

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('changePassword', $target->id)
            ->assertSee('an uppercase letter')
            ->assertDontSee('a number')
            ->assertDontSee('a symbol')
            ->assertDontSee("Can't reuse");
    }

    public function test_the_edit_form_requires_current_password_only_when_editing_your_own_account(): void
    {
        $admin = User::factory()->create(['phone' => '+220831111111']);
        $other = User::factory()->create(['phone' => '+220832222222']);

        // Editing someone else: no current_password field, no such rule.
        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('edit', $other->id)
            ->assertDontSee('Current Password')
            ->set('password', 'EditedByAdmin123!')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('EditedByAdmin123!', $other->refresh()->password_hash));

        // Editing your own account and changing the password: required.
        $component = Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('edit', $admin->id)
            ->assertSee('Current Password')
            ->set('currentPassword', 'wrong-password')
            ->set('password', 'SelfEdit123!')
            ->call('save')
            ->assertHasErrors('currentPassword');

        $component->set('currentPassword', 'password')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('SelfEdit123!', $admin->refresh()->password_hash));
    }

    public function test_the_full_page_profile_editor_requires_current_password_only_for_your_own_account(): void
    {
        $admin = User::factory()->create(['phone' => '+220833333333']);
        $other = User::factory()->create(['phone' => '+220834444444']);

        $this->actingAs($admin);

        Livewire::test(\App\Livewire\Users\UserProfile::class, ['user' => $other])
            ->assertDontSee('Current Password')
            ->set('password', 'EditedByAdmin123!')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('EditedByAdmin123!', $other->refresh()->password_hash));

        Livewire::test(\App\Livewire\Users\UserProfile::class, ['user' => $admin])
            ->assertSee('Current Password')
            ->set('currentPassword', 'wrong-password')
            ->set('password', 'SelfEdit123!')
            ->call('save')
            ->assertHasErrors('currentPassword');
    }

    public function test_an_expired_password_redirects_to_the_forced_change_page_right_after_login(): void
    {
        SecuritySetting::current()->update(['password_expiry_days' => 30]);

        $user = User::factory()->create(['password_changed_at' => now()->subDays(45)]);

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login')
            ->assertRedirect(route('password.force-change', absolute: false));
    }

    public function test_an_expired_password_keeps_the_user_pinned_to_the_forced_change_page_on_any_other_request(): void
    {
        SecuritySetting::current()->update(['password_expiry_days' => 30]);

        $user = User::factory()->create(['password_changed_at' => now()->subDays(45)]);

        $this->actingAsUser($user)->get(route('dashboard'))
            ->assertRedirect(route('password.force-change', absolute: false));
    }

    public function test_completing_the_forced_change_updates_the_timestamp_and_unblocks_other_pages(): void
    {
        SecuritySetting::current()->update(['password_expiry_days' => 30]);

        $user = User::factory()->create(['password_changed_at' => now()->subDays(45)]);
        $this->actingAs($user);

        Volt::test('pages.auth.force-password-change')
            ->set('current_password', 'password')
            ->set('password', 'FreshOne123!')
            ->set('password_confirmation', 'FreshOne123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue($user->refresh()->password_changed_at->isToday());
        $this->assertFalse($user->passwordHasExpired());

        $this->actingAsUser($user)->get(route('dashboard'))->assertOk();
    }

    public function test_expiry_is_off_when_the_setting_is_zero(): void
    {
        SecuritySetting::current()->update(['password_expiry_days' => 0]);

        $user = User::factory()->create(['password_changed_at' => now()->subYears(2)]);

        $this->assertFalse($user->passwordHasExpired());
    }

    public function test_a_password_that_no_longer_meets_the_tightened_policy_forces_a_change_at_login(): void
    {
        // Compliant when it was set, under a relaxed policy (every
        // requirement off) — then the policy is tightened, which is only
        // discoverable again at the next login, with the plaintext in
        // hand.
        SecuritySetting::current()->update([
            'password_require_uppercase' => false,
            'password_require_lowercase' => false,
            'password_require_number' => false,
            'password_require_symbol' => false,
        ]);

        $user = User::factory()->create(['password_hash' => 'alllowercase']);

        SecuritySetting::current()->update(['password_require_uppercase' => true]);

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'alllowercase')
            ->call('login')
            ->assertRedirect(route('password.force-change', absolute: false));

        $this->actingAsUser($user)->get(route('dashboard'))
            ->assertRedirect(route('password.force-change', absolute: false));
    }

    public function test_a_password_that_still_meets_the_policy_logs_in_normally(): void
    {
        $user = User::factory()->create(['password_hash' => 'FullyCompliant123!']);

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'FullyCompliant123!')
            ->call('login')
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_completing_the_forced_change_clears_the_retroactive_policy_flag(): void
    {
        // Default policy already requires a mix of character classes —
        // "alllowercase" fails it outright, no need to tighten anything.
        $user = User::factory()->create(['password_hash' => 'alllowercase']);

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'alllowercase')
            ->call('login');

        $this->actingAs($user);

        Volt::test('pages.auth.force-password-change')
            ->set('current_password', 'alllowercase')
            ->set('password', 'NowCompliant123!')
            ->set('password_confirmation', 'NowCompliant123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertFalse(session('must_change_password', false));
        $this->actingAsUser($user)->get(route('dashboard'))->assertOk();
    }

    public function test_a_login_from_a_never_before_seen_ip_is_flagged_and_notified(): void
    {
        $user = User::factory()->create();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        $this->assertTrue($this->security('login_new_device')->where('user_id', $user->id)->exists());
        $this->assertTrue(SystemNotification::where('target_user_id', $user->id)->where('category', 'user_system')->exists());
    }

    public function test_a_second_login_from_the_same_ip_is_not_flagged_again(): void
    {
        $user = User::factory()->create();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        $this->assertSame(1, $this->security('login_new_device')->where('user_id', $user->id)->count());

        Auth::guard('web')->logout();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password')
            ->call('login');

        $this->assertSame(1, $this->security('login_new_device')->where('user_id', $user->id)->count());
    }

    public function test_the_security_score_reacts_live_to_unsaved_form_edits(): void
    {
        $admin = User::factory()->create();

        $weak = Livewire::actingAs($admin)->test(\App\Livewire\Settings\SettingsManager::class)
            ->set('activeSection', 'security')
            ->set('security.max_failed_login_attempts', 5)
            ->set('security.lockout_duration_minutes', 1)
            ->set('security.password_min_length', 4)
            ->set('security.password_history_count', 0)
            ->set('security.password_expiry_days', 0)
            ->set('security.password_require_uppercase', false)
            ->set('security.password_require_lowercase', false)
            ->set('security.password_require_number', false)
            ->set('security.password_require_symbol', false)
            ->set('security.session_timeout_minutes', 600)
            ->viewData('securityScore');

        $this->assertSame('Weak', $weak['label']);

        $strong = Livewire::actingAs($admin)->test(\App\Livewire\Settings\SettingsManager::class)
            ->set('activeSection', 'security')
            ->set('security.max_failed_login_attempts', 5)
            ->set('security.lockout_duration_minutes', 15)
            ->set('security.password_min_length', 12)
            ->set('security.password_history_count', 5)
            ->set('security.password_expiry_days', 90)
            ->set('security.password_require_uppercase', true)
            ->set('security.password_require_lowercase', true)
            ->set('security.password_require_number', true)
            ->set('security.password_require_symbol', true)
            ->set('security.session_timeout_minutes', 30)
            ->viewData('securityScore');

        $this->assertSame(100, $strong['score']);
        $this->assertSame('Strong', $strong['label']);
    }

    private function security(string $action)
    {
        return AuditLog::where('module', 'security')->where('action', $action);
    }
}
