<x-layouts.app>
    @push('head')
        @vite(['resources/css/returns.css'])
    @endpush

    <x-slot name="header">
        <div class="flex items-center flex-wrap gap-x-3 gap-y-1">
            <span>{{ __('Return Management') }}</span>
            @if (auth()->user()->hasPermission('sales', 'view'))
                <a href="{{ route('sales.index') }}" wire:navigate class="hdr-link">
                    View Sales
                </a>
            @endif
        </div>
    </x-slot>

    <livewire:returns.return-manager />
</x-layouts.app>
