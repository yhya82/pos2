<x-layouts.app>
    <x-slot name="header">
        <div class="flex items-center flex-wrap gap-x-3 gap-y-1">
            <span>{{ __('Products') }}</span>
            <a href="{{ route('units.index') }}" wire:navigate class="text-sm font-normal text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
                Manage Units →
            </a>
        </div>
    </x-slot>

    <livewire:products.product-manager />
</x-layouts.app>
