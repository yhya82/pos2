<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => auth()->check() && auth()->user()->theme === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ config('app.name', 'Laravel') }}</title>

        @include('partials.theme-init')

        <!-- Fonts -->
        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        <!-- Scripts -->
        @vite(['resources/css/app.css', 'resources/js/app.js'])
    </head>
    <body class="font-sans text-gray-900 antialiased">
        <div class="min-h-screen flex items-center justify-center px-4 py-12 bg-gradient-to-b from-indigo-50 via-white to-white dark:from-gray-950 dark:via-gray-900 dark:to-gray-900">
            <div class="w-full sm:max-w-md">
                <div class="bg-white dark:bg-gray-800 rounded-2xl shadow-xl ring-1 ring-gray-900/5 dark:ring-white/10 px-8 py-10">
                    {{ $slot }}
                </div>
            </div>
        </div>
    </body>
</html>
