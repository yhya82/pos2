<?php

use App\Livewire\Forms\LoginForm;
use App\Models\LoginSession;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;
use Livewire\Attributes\Layout;
use Livewire\Volt\Component;

new #[Layout('layouts.guest')] class extends Component
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
    <div class="flex flex-col items-center text-center mb-8">
        @if ($logoUrl = \App\Models\GeneralSetting::current()?->logoUrl())
            <img src="{{ $logoUrl }}" alt="" class="h-14 w-14 rounded-xl object-cover mb-4">
        @else
            <div class="h-14 w-14 rounded-xl bg-indigo-50 dark:bg-indigo-900/40 flex items-center justify-center mb-4">
                <x-application-logo class="h-8 w-8 fill-current text-indigo-600 dark:text-indigo-400" />
            </div>
        @endif
        <h1 class="text-xl font-semibold text-gray-900 dark:text-gray-100">
            {{ \App\Models\GeneralSetting::current()->business_name ?? config('app.name') }}
        </h1>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1">Sign in to your account</p>
    </div>

    <!-- Session Status -->
    <x-auth-session-status class="mb-4" :status="session('status')" />

    <form wire:submit="login" class="space-y-5">
        <!-- Email Address -->
        <div>
            <x-input-label for="email" :value="__('Email')" />
            <x-text-input wire:model="form.email" id="email" class="block mt-1 w-full py-2.5" type="email" name="email" required autofocus autocomplete="username" />
            <x-input-error :messages="$errors->get('form.email')" class="mt-2" />
        </div>

        <!-- Password -->
        <div>
            <x-input-label for="password" :value="__('Password')" />

            <x-text-input wire:model="form.password" id="password" class="block mt-1 w-full py-2.5"
                            type="password"
                            name="password"
                            required autocomplete="current-password" />

            <x-input-error :messages="$errors->get('form.password')" class="mt-2" />
        </div>

        <div class="flex items-center justify-between gap-4 pt-1">
            @if (Route::has('password.request'))
                <a class="text-sm text-gray-500 dark:text-gray-400 hover:text-indigo-600 dark:hover:text-indigo-400 rounded-md focus:outline-none focus:ring-2 focus:ring-offset-2 focus:ring-indigo-500 dark:focus:ring-offset-gray-800" href="{{ route('password.request') }}" wire:navigate>
                    {{ __('Forgot your password?') }}
                </a>
            @endif

            <x-primary-button class="ms-auto w-full sm:w-auto justify-center">
                {{ __('Log in') }}
            </x-primary-button>
        </div>
    </form>
</div>
