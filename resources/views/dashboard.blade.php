<x-layouts.app>
    <x-slot name="header">
        {{ __('Dashboard') }}
    </x-slot>

    <div class="bg-white dark:bg-gray-800 overflow-hidden shadow-sm rounded-lg">
        <div class="p-6 text-gray-900 dark:text-gray-100">
            {{ __("You're logged in!") }}
        </div>
    </div>
</x-layouts.app>
