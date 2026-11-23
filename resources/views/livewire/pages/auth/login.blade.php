<?php

use App\Livewire\Forms\LoginForm;
use App\Models\LoginSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.login')] class extends Component
{
    public LoginForm $form;

    /**
     * Without this, a blank-field validation message reads "The
     * form.email field is required." — leaking the nested Form
     * object's property path instead of a name a person would use.
     */
    protected function validationAttributes(): array
    {
        return [
            'form.email' => 'email',
            'form.password' => 'password',
        ];
    }

    /**
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        try {
            $this->form->authenticate();
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Only the password is cleared — the email is very likely
            // correct (it's usually the password that's wrong, or the
            // account is locked/deactivated), so re-typing it too would
            // just be friction. login-failed drives the password field's
            // refocus + shake on the client.
            $this->form->password = '';
            $this->dispatch('login-failed');

            throw $e;
        }

        Session::regenerate();

        // Created with the post-regenerate session ID (see the note in
        // LoginForm::authenticate()) so EnsureSessionNotExpired can find it
        // on every later request.
        LoginSession::startFor(Auth::user(), session()->getId(), request()->ip(), request()->userAgent());

        // Retroactive enforcement: a password set under a looser policy
        // (shorter minimum, fewer required character classes) that the
        // Security Center's policy has since tightened only becomes
        // checkable again right here, with the plaintext still on hand —
        // a stored hash can't be re-validated for length or complexity.
        // The flag (not a re-derivable fact like expiry) is what
        // EnsurePasswordNotExpired keeps checking on every later request
        // until the password is actually changed.
        if (! Auth::user()->passwordMeetsCurrentPolicy($this->form->password)) {
            session(['must_change_password' => true]);
        }

        if (Auth::user()->passwordHasExpired() || session('must_change_password')) {
            $this->redirect(route('password.force-change'), navigate: true);

            return;
        }

        $this->redirectIntended(default: Auth::user()->defaultLandingRoute(), navigate: true);
    }
}; ?>

<div>
    <div class="eyebrow">Sign in</div>
    <div class="authhead">
        <h1>Welcome <span class="accent">back</span></h1>
        <p>Sign in to your account</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="notice ok" :status="session('status')" />

    <form wire:submit="login">
        <!-- Email Address -->
        <div class="f">
            <label for="email">{{ __('Email') }}</label>
            <input wire:model="form.email" id="email" type="email" name="email" required autofocus autocomplete="username">
            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div class="f">
            <label for="password">{{ __('Password') }}</label>
            <div
                class="pwfield"
                x-data="{ show: false, shake: false }"
                x-on:login-failed.window="shake = true; $refs.passwordInput.focus(); setTimeout(() => shake = false, 500)"
                :class="{ shake: shake }"
            >
                <input
                    x-ref="passwordInput"
                    :type="show ? 'text' : 'password'"
                    wire:model="form.password"
                    id="password"
                    name="password"
                    required
                    autocomplete="current-password"
                >
                <button type="button" class="eyebtn" x-on:click="show = !show" :aria-label="show ? 'Hide password' : 'Show password'">
                    <svg x-show="!show" viewBox="0 0 24 24"><path d="M1 12s4-7 11-7 11 7 11 7-4 7-11 7-11-7-11-7z"/><circle cx="12" cy="12" r="3"/></svg>
                    <svg x-show="show" x-cloak viewBox="0 0 24 24"><path d="M17.94 17.94A10.94 10.94 0 0 1 12 19c-7 0-11-7-11-7a21.8 21.8 0 0 1 5.06-5.94M9.9 4.24A9.12 9.12 0 0 1 12 4c7 0 11 7 11 7a21.77 21.77 0 0 1-2.16 3.19m-6.72-1.07a3 3 0 1 1-4.24-4.24"/><path d="M1 1l22 22"/></svg>
                </button>
            </div>
            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
        </div>

        <div class="formrow">
            @if (Route::has('password.request'))
                <a class="forgot" href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot your password?') }}
                </a>
            @endif
        </div>

        <div style="margin-top: 32px;">
            <button type="submit" class="btn">
                <span>{{ __('Log in') }}</span>
                <span class="btn-icon"><svg viewBox="0 0 24 24"><path d="M5 12h14M13 6l6 6-6 6"/></svg></span>
            </button>
        </div>
    </form>
</div>
