<x-layouts.app>
    @push('head')
        @vite(['resources/css/settings.css'])
    @endpush

    <x-slot name="header">
        {{ __('Settings') }}
    </x-slot>

    <livewire:settings.settings-manager />
</x-layouts.app>
