<?php

namespace Tests\Feature\Auth;

use App\Models\SecuritySetting;
use App\Models\User;
use Livewire\Volt\Volt;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class PasswordConfirmationTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_confirm_password_screen_can_be_rendered(): void
    {
        $user = User::factory()->create();

        $response = $this->actingAsUser($user)->get('/confirm-password');

        $response
            ->assertSeeVolt('pages.auth.confirm-password')
            ->assertStatus(200);
    }

    public function test_password_can_be_confirmed(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('pages.auth.confirm-password')
            ->set('password', 'password');

        $component->call('confirmPassword');

        $component
            ->assertRedirect('/dashboard')
            ->assertHasNoErrors();
    }

    public function test_password_is_not_confirmed_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('pages.auth.confirm-password')
            ->set('password', 'wrong-password');

        $component->call('confirmPassword');

        $component
            ->assertNoRedirect()
            ->assertHasErrors('password');
    }

    /**
     * This screen used to call Auth::guard('web')->validate() directly,
     * with no lockout at all — unlimited, unthrottled password guesses
     * against a real account, for anyone already holding a session
     * (a stolen cookie, an unlocked terminal), completely bypassing the
     * login screen's own account lockout. It now shares that exact lockout
     * via User::registerFailedLoginAttempt().
     */
    public function test_repeated_wrong_passwords_here_lock_the_account_too(): void
    {
        $user = User::factory()->create();
        $max = SecuritySetting::current()->max_failed_login_attempts;

        $this->actingAs($user);

        // The attempt that actually trips the lock still just says the
        // password was wrong (same as every other failure here) — it's the
        // NEXT attempt, caught by the isLockedOut() check at the top of
        // confirmPassword(), that reports the lockout itself.
        for ($i = 1; $i <= $max; $i++) {
            Volt::test('pages.auth.confirm-password')
                ->set('password', 'wrong-password')
                ->call('confirmPassword')
                ->assertHasErrors('password');
        }

        $this->assertTrue($user->fresh()->isLockedOut());

        $lockedAttempt = Volt::test('pages.auth.confirm-password')
            ->set('password', 'wrong-password')
            ->call('confirmPassword');

        $lockedAttempt->assertHasErrors('password');
        $this->assertStringContainsString('locked', $lockedAttempt->errors()->first('password'));

        // Even the genuinely correct password is refused now — it's the
        // account-level lockout blocking this, not a wrong guess.
        Volt::test('pages.auth.confirm-password')
            ->set('password', 'password')
            ->call('confirmPassword')
            ->assertHasErrors('password');
    }
}
