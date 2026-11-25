<x-layouts.app>
    @push('head')
        @vite(['resources/css/products.css'])
    @endpush

    <x-slot name="header">
        <div class="flex flex-col" style="gap:3px; line-height:1;">
            <span>{{ __('Product Profile') }}</span>
            <a href="{{ route('products.index') }}" wire:navigate class="backlink">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M15 19l-7-7 7-7"/></svg>
                {{ __('Back to Products') }}
            </a>
        </div>
    </x-slot>

    <livewire:products.product-profile :product="$product" />
</x-layouts.app>
