<?php

namespace Tests\Feature\Auth;

use App\Livewire\Layout\LogoutButton;
use App\Models\Role;
use App\Models\SecuritySetting;
use App\Models\User;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class AuthenticationTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_login_screen_can_be_rendered(): void
    {
        $response = $this->get('/login');

        $response
            ->assertOk()
            ->assertSeeVolt('pages.auth.login');
    }

    public function test_users_can_authenticate_using_the_login_screen(): void
    {
        // A policy-compliant password — the default security policy now
        // requires every character class, which the stock factory
        // password ("password") doesn't meet; that's covered on its own
        // in PasswordSecurityPolicyTest (retroactive enforcement at login).
        $user = User::factory()->create(['password_hash' => 'Compliant123!']);

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'Compliant123!');

        $component->call('login');

        $component
            ->assertHasNoErrors()
            ->assertRedirect(route('dashboard', absolute: false));

        $this->assertAuthenticated();
    }

    public function test_users_can_not_authenticate_with_invalid_password(): void
    {
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password');

        $component->call('login');

        $component
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_a_failed_login_clears_only_the_password_and_tells_the_client_to_refocus_it(): void
    {
        $user = User::factory()->create();

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password')
            ->call('login')
            ->assertHasErrors()
            ->assertSet('form.email', $user->email)
            ->assertSet('form.password', '')
            ->assertDispatched('login-failed');
    }

    public function test_an_unknown_email_and_a_wrong_password_both_say_invalid_credentials(): void
    {
        $user = User::factory()->create();

        // Unknown email — no account row to count attempts against.
        Volt::test('pages.auth.login')
            ->set('form.email', 'nobody@example.com')
            ->set('form.password', 'whatever')
            ->call('login')
            ->assertHasErrors()
            ->assertSee('Invalid credentials.', false);

        // A real account's first wrong attempt — same wording, plus the
        // attempts-remaining count (max_failed_login_attempts defaults to 5,
        // so 4 remain after this one failure).
        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password')
            ->call('login')
            ->assertHasErrors();

        $message = $component->errors()->first('form.email');

        $this->assertStringContainsString('Invalid credentials.', $message);
        $this->assertStringContainsString('4 attempts remaining', $message);
    }

    public function test_blank_login_fields_report_clean_field_names_not_form_dot_email(): void
    {
        $component = Volt::test('pages.auth.login')
            ->set('form.email', '')
            ->set('form.password', '')
            ->call('login')
            ->assertHasErrors(['form.email', 'form.password']);

        $this->assertSame('The email field is required.', $component->errors()->first('form.email'));
        $this->assertSame('The password field is required.', $component->errors()->first('form.password'));
    }

    public function test_repeated_wrong_passwords_lock_the_account_through_the_single_configured_lockout(): void
    {
        $user = User::factory()->create();
        $max = SecuritySetting::current()->max_failed_login_attempts;

        for ($i = 1; $i < $max; $i++) {
            $component = Volt::test('pages.auth.login')
                ->set('form.email', $user->email)
                ->set('form.password', 'wrong-password')
                ->call('login')
                ->assertHasErrors();

            $this->assertStringContainsString('Invalid credentials.', $component->errors()->first('form.email'));
        }

        // The attempt that reaches the configured threshold locks the
        // account outright — there is no separate "try again in N seconds"
        // message from a second, unrelated rate limiter.
        $lockingAttempt = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'wrong-password')
            ->call('login')
            ->assertHasErrors();

        $this->assertStringContainsString('locked', $lockingAttempt->errors()->first('form.email'));
        $this->assertTrue($user->refresh()->locked_until?->isFuture());
    }

    public function test_a_cashier_lands_on_pos_after_login_not_the_dashboard(): void
    {
        $cashier = Role::where('name', 'Cashier')->firstOrFail();
        $user = User::factory()->create(['role_id' => $cashier->id, 'password_hash' => 'Compliant123!']);

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'Compliant123!')
            ->call('login')
            ->assertRedirect(route('pos.index', absolute: false));
    }

    public function test_a_non_cashier_still_lands_on_the_dashboard_after_login(): void
    {
        $user = User::factory()->create(['password_hash' => 'Compliant123!']);

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'Compliant123!')
            ->call('login')
            ->assertRedirect(route('dashboard', absolute: false));
    }

    public function test_an_intended_url_still_wins_over_the_cashiers_pos_landing_page(): void
    {
        $cashier = Role::where('name', 'Cashier')->firstOrFail();
        $user = User::factory()->create(['role_id' => $cashier->id, 'password_hash' => 'Compliant123!']);

        $this->get(route('customers.index'));

        Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'Compliant123!')
            ->call('login')
            ->assertRedirect(route('customers.index', absolute: false));
    }

    public function test_deactivated_users_cannot_authenticate(): void
    {
        $user = User::factory()->inactive()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

        $component->call('login');

        $component
            ->assertHasErrors()
            ->assertNoRedirect();

        $this->assertGuest();
    }

    public function test_dashboard_renders_the_app_shell_for_an_authenticated_user(): void
    {
        $user = User::factory()->create();

        $this->actingAsUser($user);

        $response = $this->get('/dashboard');

        // The sidebar/header/logout button — this project's own app shell
        // (a Blade component, not Volt's stock `layout.navigation`).
        $response
            ->assertOk()
            ->assertSeeLivewire(LogoutButton::class)
            ->assertSee('Dashboard');
    }

    public function test_users_can_logout(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        Livewire::test(LogoutButton::class)
            ->call('logout')
            ->assertRedirect('/login');

        $this->assertGuest();
    }
}
