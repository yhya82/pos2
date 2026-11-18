<x-layouts.app>
    @push('head')
        @vite(['resources/css/sales.css'])
    @endpush

    <x-slot name="header">
        {{ __('Sales') }}
    </x-slot>

    <livewire:sales.sales-history />
</x-layouts.app>
