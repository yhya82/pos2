<x-layouts.app>
    @push('head')
        @vite(['resources/css/suppliers.css'])
    @endpush

    <x-slot name="header">
        {{ __('Suppliers') }}
    </x-slot>

    <livewire:suppliers.supplier-manager />
</x-layouts.app>
