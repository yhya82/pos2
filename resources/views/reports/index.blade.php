<x-layouts.app>
    @push('head')
        @vite(['resources/css/reports.css'])
    @endpush

    <x-slot name="header">
        {{ __('Reports') }}
    </x-slot>

    <livewire:reports.report-viewer />
</x-layouts.app>
