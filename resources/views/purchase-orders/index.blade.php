<x-layouts.app>
    @push('head')
        @vite(['resources/css/purchase-orders.css'])
    @endpush

    <x-slot name="header">
        {{ __('Purchase Management') }}
    </x-slot>

    <livewire:purchase-orders.purchase-order-manager />
</x-layouts.app>
