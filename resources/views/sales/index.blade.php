<x-layouts.app>
    <x-slot name="header">
        <div class="flex items-center flex-wrap gap-x-3 gap-y-1">
            <span>{{ __('Sales') }}</span>
            @if (auth()->user()->hasPermission('sales', 'create'))
                <a href="{{ route('pos.index') }}" wire:navigate class="text-sm font-normal text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
                    Go to POS →
                </a>
            @endif
        </div>
    </x-slot>

    <livewire:sales.sales-history />
</x-layouts.app>
