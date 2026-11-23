<x-layouts.app>
    @push('head')
        @vite(['resources/css/profile.css'])
    @endpush

    <x-slot name="header">
        {{ __('Profile') }}
    </x-slot>

    @php
        $user = auth()->user();
        $initials = collect(preg_split('/\s+/', trim($user->name)))->filter()->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode('');
    @endphp

    <div class="profile-skin">
        <div class="top">
            <div>
                <h1>{{ __('My Profile') }}</h1>
                <p>{{ __('Manage your personal information, password and account.') }}</p>
            </div>
        </div>

        <div class="profile-head">
            <span class="avatar" style="width:56px; height:56px; font-size:18px;">{{ $initials ?: '?' }}</span>
            <div>
                <div class="nm">
                    <h2>{{ $user->name }}</h2>
                    <span class="rolechip">{{ $user->role->name }}</span>
                </div>
                <p class="meta">
                    {{ $user->email }}
                    @if ($user->phone) &middot; {{ $user->formattedPhone() }} @endif
                </p>
            </div>
            <div class="figs">
                <div class="lbl">{{ __('Last Login') }}</div>
                <div class="val">{{ $user->last_login_at?->format('Y-m-d H:i') ?? __('Never') }}</div>
            </div>
        </div>

        <livewire:profile.update-profile-information-form />

        <livewire:profile.update-password-form />

        <livewire:profile.delete-user-form />
    </div>
</x-layouts.app>
