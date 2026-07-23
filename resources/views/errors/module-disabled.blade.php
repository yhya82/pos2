<x-layouts.app>
    <x-slot name="header">
        {{ __('Module Disabled') }}
    </x-slot>

    <div class="flex flex-col items-center justify-center text-center py-20 bg-white dark:bg-gray-800 shadow sm:rounded-lg">
        <div class="h-12 w-12 rounded-full bg-gray-100 dark:bg-gray-700 flex items-center justify-center mb-4">
            <x-icon name="cube" class="h-6 w-6 text-gray-400 dark:text-gray-500" />
        </div>
        <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $moduleLabel }} is currently disabled</h2>
        <p class="text-sm text-gray-500 dark:text-gray-400 mt-1 max-w-sm">
            An administrator has turned this module off from Settings &rsaquo; Module Management. Ask them to re-enable it if you need access.
        </p>
        <a href="{{ route('dashboard') }}" wire:navigate class="mt-6 inline-flex items-center gap-1.5 text-sm font-medium text-indigo-600 hover:text-indigo-500 dark:text-indigo-400">
            &larr; Back to Dashboard
        </a>
    </div>
</x-layouts.app>
