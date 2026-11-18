<x-layouts.app>
    @push('head')
        @vite(['resources/css/customers.css'])
    @endpush

    <x-slot name="header">
        {{ __('Customers') }}
    </x-slot>

    <livewire:customers.customer-manager />
</x-layouts.app>
