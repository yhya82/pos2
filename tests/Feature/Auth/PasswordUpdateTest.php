<?php

namespace Tests\Feature\Auth;

use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Volt\Volt;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class PasswordUpdateTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_password_can_be_updated(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-password-form')
            ->set('password', 'NewPassword123!')
            ->set('password_confirmation', 'NewPassword123!')
            ->call('updatePassword');

        $component
            ->assertHasNoErrors()
            ->assertNoRedirect();

        $this->assertTrue(Hash::check('NewPassword123!', $user->refresh()->password_hash));
    }

    public function test_confirmation_must_match_the_new_password(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user);

        $component = Volt::test('profile.update-password-form')
            ->set('password', 'NewPassword123!')
            ->set('password_confirmation', 'SomethingElse123!')
            ->call('updatePassword');

        // The mismatch error belongs on password_confirmation (what was
        // actually mistyped), not password — that's also what the
        // confirm field's shake/focus/red-highlight react to client-side.
        $component
            ->assertHasErrors(['password_confirmation'])
            ->assertDispatched('password-mismatch')
            ->assertNoRedirect();
    }
}
