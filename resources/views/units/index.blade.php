<x-layouts.app>
    @push('head')
        @vite(['resources/css/units.css'])
    @endpush

    <x-slot name="header">
        {{ __('Units') }}
    </x-slot>

    <livewire:units.unit-manager />
</x-layouts.app>
