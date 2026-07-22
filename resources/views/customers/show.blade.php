<x-layouts.app>
    <x-slot name="header">
        <a href="{{ route('customers.index') }}" wire:navigate class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">←</a>
        {{ __('Customer Profile') }}
    </x-slot>

    <livewire:customers.customer-profile :customer="$customer" />
</x-layouts.app>
