<x-layouts.app>
    <x-slot name="header">
        <a href="{{ route('users.index') }}" wire:navigate class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">←</a>
        {{ __('User Profile') }}
    </x-slot>

    <livewire:users.user-profile :user="$user" />
</x-layouts.app>
