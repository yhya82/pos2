<x-layouts.app>
    @push('head')
        @vite(['resources/css/products.css'])
    @endpush

    <x-slot name="header">
        <div class="flex items-center flex-wrap gap-x-3 gap-y-1">
            <span>{{ __('Products') }}</span>
            <a href="{{ route('units.index') }}" wire:navigate class="hdr-link">
                Manage Units
            </a>
        </div>
    </x-slot>

    <livewire:products.product-manager />
</x-layouts.app>
