<x-layouts.app>
    @push('head')
        @vite(['resources/css/users-roles.css'])
    @endpush

    <x-slot name="header">
        {{ __('Users') }}
    </x-slot>

    <livewire:users.user-manager />
</x-layouts.app>
