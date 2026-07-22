<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' - ' : '' }}{{ config('app.name', 'Laravel') }}</title>

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans antialiased bg-gray-100 dark:bg-gray-900">
        <div class="min-h-screen">
            <x-sidebar />

            <div class="md:pl-64 flex flex-col min-h-screen">
                <!-- Header: business context on the left, notifications and
                     the user menu on the right (SRS Sec. 20.4) -->
                <header class="h-16 shrink-0 flex items-center justify-between gap-4 px-4 sm:px-6 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <div class="min-w-0">
                        @isset($header)
                            <div class="font-semibold text-lg text-gray-800 dark:text-gray-100 truncate">
                                {{ $header }}
                            </div>
                        @endisset
                    </div>

                    <div class="flex items-center gap-4 shrink-0">
                        @if (\App\Models\ModuleSetting::enabled('notifications'))
                            <livewire:notifications.notification-bell />
                        @endif

                        <!-- User profile menu (SRS Sec. 20.4): name, current
                             role, profile link, logout. -->
                        <x-dropdown align="right" width="56">
                            <x-slot name="trigger">
                                <button class="flex items-center gap-2 text-sm focus:outline-none">
                                    <span class="text-right leading-tight hidden sm:block">
                                        <span class="block font-medium text-gray-800 dark:text-gray-100">{{ auth()->user()->name }}</span>
                                        <span class="block text-xs text-gray-500 dark:text-gray-400">{{ auth()->user()->role->name }}</span>
                                    </span>
                                    <svg class="fill-current h-4 w-4 text-gray-400" xmlns="http://www.w3.org/2000/svg" viewBox="0 0 20 20">
                                        <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                    </svg>
                                </button>
                            </x-slot>

                            <x-slot name="content">
                                <x-dropdown-link :href="route('profile')" wire:navigate>
                                    {{ __('Profile') }}
                                </x-dropdown-link>

                                <livewire:layout.logout-button />
                            </x-slot>
                        </x-dropdown>
                    </div>
                </header>

                <!-- Notification area for one-off success/error toasts
                     (SRS Sec. 20.5): pages dispatch a `flash-message` browser
                     event; this renders it and lets it fade on its own. -->
                <div
                    x-data="{ show: false, message: '', variant: 'success' }"
                    x-on:flash-message.window="
                        message = $event.detail.message;
                        variant = $event.detail.variant ?? 'success';
                        show = true;
                        setTimeout(() => show = false, 4000);
                    "
                    x-show="show"
                    x-transition
                    style="display: none;"
                    class="fixed top-4 right-4 z-50 max-w-sm"
                >
                    <div
                        class="rounded-md px-4 py-3 shadow-lg text-sm font-medium text-white"
                        :class="variant === 'success' ? 'bg-emerald-600' : 'bg-red-600'"
                        x-text="message"
                    ></div>
                </div>

                <main class="flex-1 p-4 sm:p-6">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
