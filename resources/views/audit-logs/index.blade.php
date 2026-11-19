<x-layouts.app>
    @push('head')
        @vite(['resources/css/audit-logs.css'])
    @endpush

    <x-slot name="header">
        {{ __('Audit Logs') }}
    </x-slot>

    <livewire:audit-logs.audit-log-viewer />
</x-layouts.app>
