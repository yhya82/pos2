<x-layouts.app>
    <x-slot name="header">
        {{ __('Profile') }}
    </x-slot>

    <div class="space-y-6">
        <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10">
            <div class="max-w-xl">
                <livewire:profile.update-profile-information-form />
            </div>
        </div>

        <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10">
            <div class="max-w-xl">
                <livewire:profile.update-password-form />
            </div>
        </div>

        <div class="p-4 sm:p-8 bg-white dark:bg-gray-800 shadow-sm rounded-xl ring-1 ring-gray-900/5 dark:ring-white/10">
            <div class="max-w-xl">
                <livewire:profile.delete-user-form />
            </div>
        </div>
    </div>
</x-layouts.app>
