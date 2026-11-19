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
     * Handle an incoming authentication request.
     */
    public function login(): void
    {
        $this->validate();

        $this->form->authenticate();

        Session::regenerate();

        // Created with the post-regenerate session ID (see the note in
        // LoginForm::authenticate()) so EnsureSessionNotExpired can find it
        // on every later request.
        LoginSession::startFor(Auth::user(), session()->getId(), request()->ip(), request()->userAgent());

        $this->redirectIntended(default: route('dashboard', absolute: false), navigate: true);
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
            <input wire:model="form.password" id="password" type="password" name="password" required autocomplete="current-password">
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
