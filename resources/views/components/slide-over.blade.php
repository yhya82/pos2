@props([
    'name',
    'show' => false,
    'title' => '',
    'maxWidth' => 'md',
])

@php
$panelWidth = match ($maxWidth) {
    '2xl' => 'sm:w-full md:w-[42rem]',
    default => 'sm:w-96 md:w-[28rem]',
};
@endphp

{{--
    SRS Sec. 20.6: editing any record uses a slide-over panel, not a
    dedicated edit page — this is the one shared implementation every
    entity's create/edit form reuses. Opened/closed the same way
    Breeze's own <x-modal> already does (open-modal/close-modal window
    events keyed by name), just sliding in from the right instead of
    appearing centered.
--}}
<div
    x-data="{ show: @js($show) }"
    x-init="$watch('show', value => {
        if (value) { document.body.classList.add('overflow-y-hidden'); }
        else { document.body.classList.remove('overflow-y-hidden'); }
    })"
    x-on:open-modal.window="$event.detail == '{{ $name }}' ? show = true : null"
    x-on:close-modal.window="$event.detail == '{{ $name }}' ? show = false : null"
    x-on:keydown.escape.window="show = false"
    x-show="show"
    class="fixed inset-0 z-50 overflow-hidden"
    style="display: {{ $show ? 'block' : 'none' }};"
>
    <div
        x-show="show"
        x-on:click="show = false"
        x-transition:enter="ease-out duration-300"
        x-transition:enter-start="opacity-0"
        x-transition:enter-end="opacity-100"
        x-transition:leave="ease-in duration-200"
        x-transition:leave-start="opacity-100"
        x-transition:leave-end="opacity-0"
        class="absolute inset-0 bg-gray-500 dark:bg-gray-900 opacity-75"
    ></div>

    <div
        x-show="show"
        x-transition:enter="transform transition ease-in-out duration-300"
        x-transition:enter-start="translate-x-full"
        x-transition:enter-end="translate-x-0"
        x-transition:leave="transform transition ease-in-out duration-200"
        x-transition:leave-start="translate-x-0"
        x-transition:leave-end="translate-x-full"
        class="absolute inset-y-0 right-0 w-full {{ $panelWidth }} bg-white dark:bg-gray-800 shadow-xl flex flex-col"
    >
        <div class="flex items-center justify-between px-4 py-4 border-b border-gray-200 dark:border-gray-700 shrink-0">
            <h2 class="text-lg font-semibold text-gray-800 dark:text-gray-100">{{ $title }}</h2>
            <button type="button" x-on:click="show = false" class="text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <span class="sr-only">Close</span>
                <svg xmlns="http://www.w3.org/2000/svg" class="h-6 w-6" fill="none" viewBox="0 0 24 24" stroke="currentColor" stroke-width="1.5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <div class="flex-1 overflow-y-auto px-4 py-4">
            {{ $slot }}
        </div>

        @isset($footer)
            <div class="px-4 py-4 border-t border-gray-200 dark:border-gray-700 flex items-center justify-end gap-3 shrink-0">
                {{ $footer }}
            </div>
        @endisset
    </div>
</div>
