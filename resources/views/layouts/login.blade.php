<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}" @class(['dark' => auth()->check() && auth()->user()->theme === 'dark'])>
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <meta name="csrf-token" content="{{ csrf_token() }}">

        <title>{{ \App\Models\GeneralSetting::current()->business_name ?? config('app.name') }}</title>

        @include('partials.theme-init')

        @vite(['resources/css/app.css', 'resources/css/login.css', 'resources/js/app.js'])
    </head>
    <body class="antialiased">
        <div class="login-skin">
            <div class="splitscreen">

                <div class="brandpane">
                    <div class="texture"></div>
                    <svg class="mark-watermark" viewBox="0 0 24 24"><path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-8h6v8"/></svg>

                    <div class="bmark">
                        <span class="logo on-brand">
                            @if ($logoUrl = \App\Models\GeneralSetting::current()?->logoUrl())
                                <img src="{{ $logoUrl }}" alt="" style="width:100%; height:100%; object-fit: cover;">
                            @else
                                <svg viewBox="0 0 24 24"><path d="M3 21V8l9-5 9 5v13"/><path d="M9 21v-8h6v8"/></svg>
                            @endif
                        </span>
                        <span class="nm">{{ \App\Models\GeneralSetting::current()->business_name ?? config('app.name') }}</span>
                    </div>

                    <h2>Run the whole shop from <span class="hl">one screen</span>.</h2>
                    <p>From the till to your supplier orders — everything stays in sync, the moment you sign in.</p>

                    <div class="feats">
                        <div class="feat"><span class="ico"><svg viewBox="0 0 24 24"><path d="M3 8l9-5 9 5-9 5-9-5z"/><path d="M3 8v8l9 5 9-5V8"/><path d="M12 13v8"/></svg></span>Real-time stock across every till</div>
                        <div class="feat"><span class="ico"><svg viewBox="0 0 24 24"><rect x="6" y="4" width="12" height="17"/><path d="M9 4V3h6v1"/><path d="M9 10h6M9 14h6"/></svg></span>Purchase orders and supplier claims</div>
                        <div class="feat"><span class="ico"><svg viewBox="0 0 24 24"><path d="M5 19V11"/><path d="M12 19V5"/><path d="M19 19v-7"/><path d="M3 19h18"/></svg></span>Reports and audit history, on demand</div>
                        <div class="feat"><span class="ico"><svg viewBox="0 0 24 24"><path d="M16 21v-2a4 4 0 0 0-4-4H6a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M22 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg></span>Customer accounts, credit sales and returns</div>
                    </div>
                </div>

                <div class="formpane dotfield">
                    <button
                        type="button"
                        class="themetoggle"
                        x-data="{ dark: document.documentElement.classList.contains('dark') }"
                        x-on:click="
                            dark = !dark;
                            document.documentElement.classList.toggle('dark', dark);
                            try { localStorage.setItem('login-theme', dark ? 'dark' : 'light'); } catch (e) {}
                        "
                        :aria-label="dark ? 'Switch to light theme' : 'Switch to dark theme'"
                        title="Light / dark — remembered on this device"
                    >
                        <svg x-show="!dark" viewBox="0 0 24 24" x-cloak><path d="M20 14.5A8.5 8.5 0 1 1 9.5 4a7 7 0 0 0 10.5 10.5z"/></svg>
                        <svg x-show="dark" viewBox="0 0 24 24" x-cloak><circle cx="12" cy="12" r="4.5"/><path d="M12 2v3M12 19v3M4.2 4.2l2.1 2.1M17.7 17.7l2.1 2.1M2 12h3M19 12h3M4.2 19.8l2.1-2.1M17.7 6.3l2.1-2.1"/></svg>
                    </button>

                    <div class="authcard">
                        <div class="ctr"></div>
                        {{ $slot }}
                    </div>
                </div>

            </div>
        </div>
    </body>
</html>
