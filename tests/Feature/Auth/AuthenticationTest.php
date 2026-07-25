<?php

namespace Tests\Feature\Auth;

use App\Livewire\Layout\LogoutButton;
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
        $user = User::factory()->create();

        $component = Volt::test('pages.auth.login')
            ->set('form.email', $user->email)
            ->set('form.password', 'password');

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
            ->assertRedirect('/');

        $this->assertGuest();
    }
}
