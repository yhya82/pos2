<x-layouts.app>
    @push('head')
        @vite(['resources/css/categories.css'])
    @endpush

    <x-slot name="header">
        {{ __('Categories') }}
    </x-slot>

    <livewire:categories.category-manager />
</x-layouts.app>
