<x-layouts.app>
    <x-slot name="header">
        <div class="flex items-center gap-3">
            <span>{{ __('Return Management') }}</span>
            @if (auth()->user()->hasPermission('sales', 'view'))
                <a href="{{ route('sales.index') }}" wire:navigate class="text-sm font-normal text-indigo-600 hover:text-indigo-800 dark:text-indigo-400 dark:hover:text-indigo-300">
                    View Sales →
                </a>
            @endif
        </div>
    </x-slot>

    <livewire:returns.return-manager />
</x-layouts.app>
