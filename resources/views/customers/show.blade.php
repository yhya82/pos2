<x-layouts.app>
    @push('head')
        @vite(['resources/css/customers.css'])
    @endpush

    <x-slot name="header">
        <div class="flex flex-col" style="gap:3px; line-height:1;">
            <span>{{ __('Customer Profile') }}</span>
            <a href="{{ route('customers.index') }}" wire:navigate class="backlink">
                <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2.3" stroke-linecap="round" stroke-linejoin="round"><path d="M15 19l-7-7 7-7"/></svg>
                {{ __('Back to Customers') }}
            </a>
        </div>
    </x-slot>

    <livewire:customers.customer-profile :customer="$customer" />
</x-layouts.app>
