<x-layouts.app>
    @push('head')
        @vite(['resources/css/users-roles.css'])
    @endpush

    <x-slot name="header">
        {{ __('Roles & Permissions') }}
    </x-slot>

    <livewire:roles.role-manager />
</x-layouts.app>
