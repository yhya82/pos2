<x-layouts.app>
    @push('head')
        @vite(['resources/css/pos-terminal.css'])
    @endpush

    <x-slot name="header">
        <livewire:pos.till-header />
    </x-slot>

    <livewire:pos.terminal />
</x-layouts.app>
