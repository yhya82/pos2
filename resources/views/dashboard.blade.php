<x-layouts.app>
    @push('head')
        @vite(['resources/css/dashboard.css'])
    @endpush

    <x-slot name="header">
        {{ __('Dashboard') }}
    </x-slot>

    <livewire:dashboard.dashboard-overview />
</x-layouts.app>
