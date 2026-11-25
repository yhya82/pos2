<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Volt\Volt;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class PasswordResetTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_reset_password_link_screen_can_be_rendered(): void
    {
        $response = $this->get('/forgot-password');

        $response
            ->assertSeeVolt('pages.auth.forgot-password')
            ->assertStatus(200);
    }

    public function test_reset_password_link_can_be_requested(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, ResetPassword::class);
    }

    public function test_reset_password_screen_can_be_rendered(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) {
            $response = $this->get('/reset-password/'.$notification->token);

            $response
                ->assertSeeVolt('pages.auth.reset-password')
                ->assertStatus(200);

            return true;
        });
    }

    public function test_password_can_be_reset_with_valid_token(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $component = Volt::test('pages.auth.reset-password', ['token' => $notification->token])
                ->set('email', $user->email)
                ->set('password', 'NewPassword123!')
                ->set('password_confirmation', 'NewPassword123!');

            $component->call('resetPassword');

            $component
                ->assertRedirect('/login')
                ->assertHasNoErrors();

            return true;
        });
    }

    /**
     * config('auth.passwords.users.expire') (60 minutes) is Laravel's own
     * PasswordBroker enforcement, not custom code — but nothing exercised
     * it here before, despite expiry being one of the properties this
     * screen's whole security model depends on.
     */
    public function test_an_expired_reset_token_is_refused(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            $this->travel(config('auth.passwords.users.expire') + 1)->minutes();

            Volt::test('pages.auth.reset-password', ['token' => $notification->token])
                ->set('email', $user->email)
                ->set('password', 'NewPassword123!')
                ->set('password_confirmation', 'NewPassword123!')
                ->call('resetPassword')
                ->assertHasErrors('email');

            return true;
        });

        $this->assertTrue(Hash::check('password', $user->fresh()->password_hash));
    }

    /**
     * A reset token must be single-use — replaying it for a second reset
     * after it already succeeded once must fail, same as it would for an
     * attacker who intercepted an already-used link.
     */
    public function test_a_used_reset_token_cannot_be_replayed(): void
    {
        Notification::fake();

        $user = User::factory()->create();

        Volt::test('pages.auth.forgot-password')
            ->set('email', $user->email)
            ->call('sendPasswordResetLink');

        Notification::assertSentTo($user, ResetPassword::class, function ($notification) use ($user) {
            Volt::test('pages.auth.reset-password', ['token' => $notification->token])
                ->set('email', $user->email)
                ->set('password', 'FirstReset123!')
                ->set('password_confirmation', 'FirstReset123!')
                ->call('resetPassword')
                ->assertHasNoErrors();

            Volt::test('pages.auth.reset-password', ['token' => $notification->token])
                ->set('email', $user->email)
                ->set('password', 'SecondReset123!')
                ->set('password_confirmation', 'SecondReset123!')
                ->call('resetPassword')
                ->assertHasErrors('email');

            return true;
        });

        $this->assertTrue(Hash::check('FirstReset123!', $user->fresh()->password_hash));
    }
}
