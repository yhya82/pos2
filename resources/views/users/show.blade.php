<x-layouts.app>
    @push('head')
        @vite(['resources/css/users-roles.css'])
    @endpush

    <x-slot name="header">
        <div class="flex flex-col" style="gap:3px; line-height:1;">
            <span>{{ __('User Profile') }}</span>
            <a href="{{ route('users.index') }}" wire:navigate class="backlink">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M15 19l-7-7 7-7"/></svg>
                {{ __('Back to Users') }}
            </a>
        </div>
    </x-slot>

    <livewire:users.user-profile :user="$user" />
</x-layouts.app>
