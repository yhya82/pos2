<?php

use App\Livewire\Actions\Logout;
use Illuminate\Support\Facades\Auth;
use Livewire\Volt\Component;

new class extends Component
{
    public string $password = '';

    /**
     * Delete the currently authenticated user.
     */
    public function deleteUser(Logout $logout): void
    {
        $this->validate([
            'password' => ['required', 'string', 'current_password'],
        ]);

        tap(Auth::user(), $logout(...))->delete();

        $this->redirect('/', navigate: true);
    }
}; ?>

<div class="card danger">
    <div class="cardhead danger">
        <span class="ic"><svg viewBox="0 0 24 24"><path d="M12 9v3.75m0 3.75h.008M10.29 3.86L1.82 18a1.5 1.5 0 001.29 2.25h17.78a1.5 1.5 0 001.29-2.25L13.71 3.86a1.5 1.5 0 00-2.42 0z"/></svg></span>
        <h3>{{ __('Delete Account') }}</h3>
    </div>

    <div class="cardbody dangerrow">
        <p>{{ __('Once your account is deleted, all of its resources and data will be permanently deleted. This cannot be undone.') }}</p>

        <button
            type="button"
            class="btn danger"
            x-data=""
            x-on:click.prevent="$dispatch('open-modal', 'confirm-user-deletion')"
        >{{ __('Delete Account') }}</button>
    </div>

    <x-modal name="confirm-user-deletion" :show="$errors->isNotEmpty()" focusable>
        <form wire:submit="deleteUser" class="dialog">
            <div class="dh">
                <h2>{{ __('Delete your account?') }}</h2>
                <p>{{ __('This permanently deletes your account and all of its data. Enter your password to confirm — this cannot be undone.') }}</p>
            </div>

            <div class="db">
                <x-input-label for="password" value="{{ __('Password') }}" class="sr-only" />

                <input
                    wire:model="password"
                    id="password"
                    name="password"
                    type="password"
                    placeholder="{{ __('Password') }}"
                    style="width: 100%; height: 40px; border: 1px solid var(--line); background: var(--bg); padding: 0 12px; font: inherit; font-size: 13.5px; color: var(--ink);"
                >

                <x-input-error :messages="$errors->get('password')" class="mt-2" />
            </div>

            <div class="df">
                <button type="button" class="btn" x-on:click="$dispatch('close')">
                    {{ __('Cancel') }}
                </button>

                <button type="submit" class="btn danger">
                    {{ __('Delete Account') }}
                </button>
            </div>
        </form>
    </x-modal>
</div>
