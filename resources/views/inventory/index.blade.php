<x-layouts.app>
    @push('head')
        @vite(['resources/css/inventory.css'])
    @endpush

    <x-slot name="header">
        {{ __('Inventory') }}
    </x-slot>

    <livewire:inventory.inventory-overview />
</x-layouts.app>
