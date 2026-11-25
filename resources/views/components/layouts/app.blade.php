<!DOCTYPE html>
{{--
    The dark class is baked in here server-side (not left purely to the
    inline script below) because wire:navigate fetches a whole fresh
    document per page and reconciles <html>'s attributes against whatever
    that fetch actually contains — a class only ever added by client JS
    after the fact isn't in that markup, so it was getting dropped on every
    SPA-style navigation even though it survived a plain reload. Baking it
    into the attribute means every fetched page is already correct from the
    first byte; the script below still handles 'system' (OS-following,
    unresolvable server-side) and the instant same-page toggle.
--}}
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => auth()->check() && auth()->user()->theme === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ isset($title) ? $title.' - ' : '' }}{{ config('app.name', 'Laravel') }}</title>

        @include('partials.theme-init')

        <link rel="preconnect" href="https://fonts.bunny.net">
        <link href="https://fonts.bunny.net/css?family=figtree:400,500,600&display=swap" rel="stylesheet" />

        @vite(['resources/css/app.css', 'resources/css/sidebar.css', 'resources/css/notifications-bell.css', 'resources/css/user-menu.css', 'resources/css/flash-message.css', 'resources/js/app.js'])
        @stack('head')
    </head>
    <body
        class="font-sans antialiased bg-gray-100 dark:bg-gray-900"
        x-data
        x-on:theme-changed.window="document.documentElement.classList.toggle('dark',
            $event.detail.theme === 'dark' ||
            ($event.detail.theme === 'system' && window.matchMedia('(prefers-color-scheme: dark)').matches)
        )"
    >
        {{-- A dropped connection shows here, not as the one-off flash toast
             (top-right, auto-fades) — "offline" is an ongoing state, not an
             event, so it needs its own persistent banner that only clears
             once the connection actually comes back. Two independent
             triggers: the browser's own online/offline events (catches a
             fully-dead network immediately) and a failed Livewire request
             (catches a connection that drops mid-click even while
             navigator.onLine still optimistically reports true). --}}
        <div
            x-data="{ offline: !navigator.onLine }"
            x-on:offline.window="offline = true"
            x-on:online.window="offline = false"
            x-on:livewire-request-failed.window="offline = true"
            x-show="offline"
            x-transition
            style="display: none;"
            class="fixed top-0 inset-x-0 z-[60] bg-amber-600 text-white text-center text-sm font-semibold py-2 px-4"
        >
            {{ __("No internet connection — nothing will save until you're back online.") }}
        </div>

        {{-- data-navigate-once: see the note on the presence-channel script
             just below — without it, this hook gets registered again on
             every wire:navigate page swap, firing the same failed-request
             callback once per page visited this session. --}}
        <script data-navigate-once>
            document.addEventListener('livewire:init', () => {
                Livewire.hook('request', ({ fail }) => {
                    fail(({ status }) => {
                        // Set only in sendRequest()'s fetch() catch — a real
                        // network-level failure (DNS, offline, connection
                        // refused), not a server-side error response like a
                        // 419/500, which already has its own real page to show.
                        if (status === 503) {
                            window.dispatchEvent(new Event('livewire-request-failed'));
                        }
                    });
                });
            });
        </script>

        @auth
            {{-- Every authenticated tab joins the presence channel so the
                 admin-only Online Users widget (App\Livewire\Dashboard\OnlineUsers)
                 has an accurate live roster — membership itself is handled
                 natively by Reverb, this just forwards it into Livewire.
                 data-navigate-once: wire:navigate re-runs any body <script>
                 on every page swap by default, and Echo.join() returns the
                 same cached channel each time while .here()/.joining()/
                 .leaving() only ever add another listener — without this
                 attribute, every navigation stacks one more listener on top
                 of the last, so a single presence event ends up firing this
                 dispatch once per page visited this session. --}}
            <script data-navigate-once>
                window.Echo.join('online-users')
                    .here(users => Livewire.dispatch('online-users-synced', { users }))
                    .joining(user => Livewire.dispatch('online-user-joined', { user }))
                    .leaving(user => Livewire.dispatch('online-user-left', { user }));
            </script>
        @endauth

        <div class="min-h-screen">
            <livewire:layout.sidebar />

            <div class="flex flex-col min-h-screen transition-[padding] duration-200 ease-in-out" :class="$store.sidebar.collapsed ? 'md:pl-16' : 'md:pl-64'">
                <!-- Header: business context on the left, notifications and
                     the user menu on the right (SRS Sec. 20.4) -->
                <header id="app-header" class="min-h-16 shrink-0 flex items-center flex-wrap justify-between gap-x-4 gap-y-2 px-4 sm:px-6 py-3 border-b border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
                    <div class="flex items-center gap-3 min-w-0">
                        <button
                            type="button"
                            x-on:click="$store.sidebar.open = true"
                            class="md:hidden -ml-1 p-2 rounded-md text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700"
                        >
                            <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-6 w-6">
                                <path stroke-linecap="round" stroke-linejoin="round" d="M3.75 6.75h16.5M3.75 12h16.5M3.75 17.25h16.5" />
                            </svg>
                        </button>
                        <button
                            type="button"
                            x-on:click="$store.sidebar.toggleCollapsed()"
                            class="hidden md:inline-flex -ml-1 p-2 rounded-md text-gray-500 hover:bg-gray-100 dark:text-gray-400 dark:hover:bg-gray-700"
                            :title="$store.sidebar.collapsed ? 'Expand sidebar' : 'Collapse sidebar'"
                        >
                            <x-icon name="chevron-double-left" class="h-5 w-5 transition-transform duration-200" x-bind:class="{ 'rotate-180': $store.sidebar.collapsed }" />
                        </button>
                        <div class="min-w-0">
                            @isset($header)
                                <div class="font-semibold text-lg text-gray-800 dark:text-gray-100 break-words">
                                    {{ $header }}
                                </div>
                            @endisset
                        </div>
                    </div>

                    <div class="flex items-center gap-4 shrink-0">
                        <livewire:layout.theme-toggle />

                        @if (\App\Models\ModuleSetting::enabled('notifications'))
                            <livewire:notifications.notification-bell />
                        @endif

                        <!-- User profile menu (SRS Sec. 20.4): name, current
                             role, profile link, logout. -->
                        @php
                            $headerInitials = collect(preg_split('/\s+/', trim(auth()->user()->name)))->filter()->map(fn ($part) => mb_strtoupper(mb_substr($part, 0, 1)))->take(2)->implode('');
                        @endphp
                        <div class="user-menu" x-data="{ open: false }" x-on:click.outside="open = false" x-on:keydown.escape.window="open = false">
                            <button type="button" class="user-menu-trigger" x-on:click="open = ! open" x-bind:aria-expanded="open" aria-haspopup="true">
                                <span class="av">{{ $headerInitials ?: '?' }}</span>
                                <span class="who hidden sm:block">
                                    <span class="nm">{{ auth()->user()->name }}</span>
                                    <span class="rl">{{ auth()->user()->role->name }}</span>
                                </span>
                                <svg class="chev" width="14" height="14" viewBox="0 0 20 20" style="fill:currentColor; stroke:none;">
                                    <path fill-rule="evenodd" d="M5.293 7.293a1 1 0 011.414 0L10 10.586l3.293-3.293a1 1 0 111.414 1.414l-4 4a1 1 0 01-1.414 0l-4-4a1 1 0 010-1.414z" clip-rule="evenodd" />
                                </svg>
                            </button>

                            <div class="user-menu-panel absolute" x-show="open" x-transition x-on:click="open = false" style="display: none;">
                                <div class="hd">
                                    <div class="nm">{{ auth()->user()->name }}</div>
                                    <div class="em">{{ auth()->user()->email }}</div>
                                </div>
                                <a href="{{ route('profile') }}" wire:navigate class="item">
                                    <svg viewBox="0 0 24 24"><circle cx="12" cy="8" r="3.4"/><path d="M5.5 20c0-3.6 2.9-6.5 6.5-6.5s6.5 2.9 6.5 6.5"/></svg>
                                    {{ __('Profile') }}
                                </a>

                                <livewire:layout.logout-button />
                            </div>
                        </div>
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
                        clearTimeout(window.__flashTimer);
                        window.__flashTimer = setTimeout(() => show = false, variant === 'warning' ? 12000 : 4000);
                    "
                    x-show="show"
                    x-transition
                    style="display: none;"
                    class="fixed top-4 right-4 z-50"
                >
                    <div
                        class="flash-toast"
                        :class="variant === 'success' ? 'ok' : (variant === 'warning' ? 'warn' : 'danger')"
                    >
                        <svg x-show="variant === 'success'" class="ic" viewBox="0 0 24 24"><path d="M5 13l4 4L19 7"/></svg>
                        <svg x-show="variant === 'warning'" class="ic" viewBox="0 0 24 24" style="display:none"><path d="M12 9v3.5M12 16h.01M10.9 4.3L2.4 18a1.3 1.3 0 001.1 2h17a1.3 1.3 0 001.1-2L13.1 4.3a1.3 1.3 0 00-2.2 0z"/></svg>
                        <svg x-show="variant !== 'success' && variant !== 'warning'" class="ic" viewBox="0 0 24 24" style="display:none"><path d="M6 6l12 12M18 6L6 18"/></svg>
                        <span class="msg" x-text="message"></span>
                        <button type="button" class="close" x-on:click="show = false" aria-label="{{ __('Dismiss') }}">
                            <svg viewBox="0 0 24 24"><path d="M6 6l12 12M18 6L6 18"/></svg>
                        </button>
                    </div>
                </div>

                <main class="flex-1 p-4 sm:p-6">
                    {{ $slot }}
                </main>
            </div>
        </div>
    </body>
</html>
