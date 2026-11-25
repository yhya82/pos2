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
            ->set('password', 'FirstOne123!')
            ->set('password_confirmation', 'FirstOne123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        // "Changing" to the exact password just set — it's the most
        // recent entry in history, so it's a reuse like any other.
        Volt::test('profile.update-password-form')
            ->set('password', 'FirstOne123!')
            ->set('password_confirmation', 'FirstOne123!')
            ->call('updatePassword')
            ->assertHasErrors('password');

        $this->assertTrue($this->security('password_reused_blocked')->where('user_id', $user->id)->exists());
    }

    /**
     * A brand-new install's placeholder account (schema seed) gets a
     * deliberately unusable history row ('INVALID-PLACEHOLDER-...', not a
     * real hash) so nobody can log in with a known default. Hash::check()
     * throws rather than returning false for a string that isn't a
     * recognized hash at all — without a try/catch around it, that single
     * seeded row crashed every subsequent password change for that account.
     */
    public function test_an_unreadable_seeded_history_row_does_not_crash_a_password_change(): void
    {
        $user = User::factory()->create();
        \App\Models\PasswordHistory::create([
            'user_id' => $user->id,
            'password_hash' => 'INVALID-PLACEHOLDER-REPLACE-VIA-PASSWORD-RESET-FLOW',
        ]);

        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('password', 'BrandNew123!')
            ->set('password_confirmation', 'BrandNew123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('BrandNew123!', $user->refresh()->password_hash));
    }

    /**
     * password_history_count is configurable up to 50, but
     * User::setPassword() used to hard-prune history to the latest 20 rows
     * regardless — so a history_count set above 20 (which the Security
     * Center's own tips actively encourage raising) silently couldn't be
     * enforced past the 20th most recent password, with no error anywhere.
     */
    public function test_password_history_beyond_20_is_still_enforced_when_configured_higher(): void
    {
        SecuritySetting::current()->update(['password_history_count' => 30]);

        $user = User::factory()->create();

        for ($i = 1; $i <= 25; $i++) {
            $user->setPassword("Password{$i}Aa!");
        }

        $this->assertSame(25, \App\Models\PasswordHistory::where('user_id', $user->id)->count());
        $this->assertTrue($user->passwordWasUsedRecently('Password3Aa!'));
    }

    public function test_history_check_is_off_when_the_setting_is_zero(): void
    {
        SecuritySetting::current()->update(['password_history_count' => 0]);

        $user = User::factory()->create();
        $this->actingAs($user);

        Volt::test('profile.update-password-form')
            ->set('password', 'FirstOne123!')
            ->set('password_confirmation', 'FirstOne123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        // Changing straight back is allowed once the rule is disabled.
        Volt::test('profile.update-password-form')
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
            ->set('password', 'Correct123!')
            ->set('password_confirmation', 'Correct123!')
            ->call('updatePassword')
            ->assertHasNoErrors();

        $this->assertNull(LoginSession::findActiveBySessionId('some-other-device-session'));
    }

    public function test_resetting_an_admins_own_or_someone_elses_session_only_happens_through_change_password_not_edit(): void
    {
        // An admin resetting someone's password is exclusively the
        // standalone "Change Password" action now — see the next test.
        // Editing a user never revokes sessions, because editing can no
        // longer touch the password at all (see the edit-form tests below).
        $admin = User::factory()->create();
        $target = User::factory()->create();
        LoginSession::startFor($target, 'targets-own-session', '10.0.0.6', 'Target device');
        $originalHash = $target->password_hash;

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->set('editingUserId', $target->id)
            ->set('name', $target->name)
            ->set('username', $target->username)
            ->set('phone', '831234567')
            ->set('roleId', $target->role_id)
            ->set('status', 'active')
            ->set('password', 'IgnoredWhileEditing123!')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertNotNull(LoginSession::findActiveBySessionId('targets-own-session'));
        $this->assertSame($originalHash, $target->refresh()->password_hash);
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
            ->set('confirmPassword', 'alllowercase')
            ->call('savePassword')
            ->assertHasErrors('newPassword');

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('changePassword', $target->id)
            ->set('newPassword', 'ResetFromIndex123!')
            ->set('confirmPassword', 'ResetFromIndex123!')
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
            ->set('confirmPassword', 'ResetFromIndex123!')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('ResetFromIndex123!', $target->refresh()->password_hash));
    }

    public function test_confirm_password_is_required_and_must_match_the_new_password(): void
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();

        // Blank confirm password.
        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('changePassword', $target->id)
            ->assertSee('Confirm Password')
            ->set('newPassword', 'ResetFromIndex123!')
            ->call('savePassword')
            ->assertHasErrors('confirmPassword');

        // Mismatched confirm password.
        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('changePassword', $target->id)
            ->set('newPassword', 'ResetFromIndex123!')
            ->set('confirmPassword', 'SomethingElse123!')
            ->call('savePassword')
            ->assertHasErrors('confirmPassword');

        $this->assertFalse(Hash::check('ResetFromIndex123!', $target->refresh()->password_hash));
    }

    public function test_changing_your_own_password_from_the_index_needs_no_current_password_and_keeps_this_session(): void
    {
        // This is an admin tool (users,update already gates it). The
        // self-service Profile page's own password form follows the same
        // rule now — no current_password field there either.
        $admin = User::factory()->create();
        LoginSession::startFor($admin, session()->getId(), '10.0.0.1', 'PHPUnit');

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('changePassword', $admin->id)
            ->assertDontSee('Current Password')
            ->set('newPassword', 'SelfChange123!')
            ->set('confirmPassword', 'SelfChange123!')
            ->call('savePassword')
            ->assertHasNoErrors();

        $this->assertTrue(Hash::check('SelfChange123!', $admin->refresh()->password_hash));
        $this->assertNotNull(LoginSession::findActiveBySessionId(session()->getId()));
    }

    public function test_the_checklist_shows_the_configured_minimum_length_and_a_reuse_note_when_history_is_on(): void
    {
        SecuritySetting::current()->update([
            'password_min_length' => 10,
            'password_history_count' => 3,
        ]);

        $admin = User::factory()->create();
        $target = User::factory()->create();

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('changePassword', $target->id)
            ->assertSee('At least 10 characters')
            ->assertSee("Can't reuse your last 3 passwords");
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
            ->assertSee('An uppercase letter')
            ->assertDontSee('A lowercase letter')
            ->assertDontSee('A number')
            ->assertDontSee('A symbol')
            ->assertDontSee("Can't reuse");
    }

    public function test_the_edit_form_never_shows_or_changes_a_password_self_or_other(): void
    {
        $admin = User::factory()->create(['phone' => '+220831111111']);
        $other = User::factory()->create(['phone' => '+220832222222']);
        $adminHash = $admin->password_hash;
        $otherHash = $other->password_hash;

        // Editing someone else: no password field, no current_password
        // field — setting the property directly (bypassing the now-absent
        // field) still changes nothing, since editing no longer touches it.
        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('edit', $other->id)
            ->assertDontSee('wire:model="password"', false)
            ->assertDontSee('Current Password')
            ->set('password', 'EditedByAdmin123!')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($otherHash, $other->refresh()->password_hash);

        // Editing your own account: same — no password field either.
        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('edit', $admin->id)
            ->assertDontSee('wire:model="password"', false)
            ->assertDontSee('Current Password')
            ->set('password', 'SelfEdit123!')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($adminHash, $admin->refresh()->password_hash);
    }

    public function test_the_create_form_still_requires_a_password_for_a_brand_new_account(): void
    {
        $admin = User::factory()->create();
        $role = \App\Models\Role::first();

        Livewire::actingAs($admin)->test(\App\Livewire\Users\UserManager::class)
            ->call('create')
            ->assertSee('wire:model="password"', false)
            ->set('name', 'New Person')
            ->set('username', 'newperson')
            ->set('phone', '831112222')
            ->set('roleId', $role->id)
            ->call('save')
            ->assertHasErrors('password');
    }

    public function test_the_full_page_profile_editor_never_shows_or_changes_a_password(): void
    {
        $admin = User::factory()->create(['phone' => '+220833333333']);
        $otherHash = User::factory()->create(['phone' => '+220834444444'])->password_hash;
        $other = User::where('phone', '+220834444444')->first();

        $this->actingAs($admin);

        Livewire::test(\App\Livewire\Users\UserProfile::class, ['user' => $other])
            ->assertDontSee('Current Password')
            ->assertDontSee('New Password')
            ->call('editDetails')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($otherHash, $other->refresh()->password_hash);
    }

    public function test_the_account_details_card_starts_read_only_and_toggles_into_an_edit_form(): void
    {
        $admin = User::factory()->create(['name' => 'Ada Admin', 'phone' => '+220835555555']);

        $this->actingAs($admin);

        Livewire::test(\App\Livewire\Users\UserProfile::class, ['user' => $admin])
            ->assertSet('editingDetails', false)
            ->assertSee('Ada Admin')
            ->assertDontSee('wire:model="name"', false)
            ->call('editDetails')
            ->assertSet('editingDetails', true)
            ->assertSee('wire:model="name"', false)
            ->call('cancelEditDetails')
            ->assertSet('editingDetails', false);
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
