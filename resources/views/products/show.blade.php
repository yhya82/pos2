<x-layouts.app>
    <x-slot name="header">
        <a href="{{ route('products.index') }}" wire:navigate class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">←</a>
        {{ __('Product Profile') }}
    </x-slot>

    <livewire:products.product-profile :product="$product" />
</x-layouts.app>
