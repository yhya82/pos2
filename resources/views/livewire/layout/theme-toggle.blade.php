<div
    x-data="{ dark: document.documentElement.classList.contains('dark') }"
    x-on:theme-changed.window="dark = $event.detail.theme === 'dark' || ($event.detail.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)"
>
    <button
        type="button"
        wire:click="toggle"
        class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200 transition-colors"
        :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'"
    >
        <x-icon x-show="!dark" name="moon" class="h-5 w-5" x-cloak />
        <x-icon x-show="dark" name="sun" class="h-5 w-5" x-cloak />
    </button>
</div>
