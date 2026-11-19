<x-layouts.app>
    @push('head')
        @vite(['resources/css/notifications.css'])
    @endpush

    <x-slot name="header">
        {{ __('Notifications') }}
    </x-slot>

    <livewire:notifications.notification-list />
</x-layouts.app>
