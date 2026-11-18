<div>
    {{-- Mobile backdrop — only ever relevant below md, where the sidebar
         becomes a slide-over instead of the always-visible desktop rail. --}}
    <div
        x-show="$store.sidebar.open"
        x-transition.opacity
        x-on:click="$store.sidebar.open = false"
        class="fixed inset-0 bg-gray-900/50 z-30 md:hidden"
        style="display: none;"
    ></div>

    <aside
        class="side-skin fixed inset-y-0 left-0 z-40 w-64 flex flex-col transition-all duration-200 ease-in-out md:translate-x-0"
        :class="[$store.sidebar.open ? 'translate-x-0' : '-translate-x-full', $store.sidebar.collapsed ? 'md:w-16' : 'md:w-64']"
    >
        <div
            class="brand-bar flex items-center justify-between h-16 px-6 shrink-0"
            :class="$store.sidebar.collapsed ? 'md:justify-center md:px-2' : ''"
        >
            <a href="{{ route('dashboard') }}" wire:navigate x-on:click="$store.sidebar.open = false" class="flex items-center gap-2 min-w-0">
                @if ($generalSettings?->logoUrl())
                    <img src="{{ $generalSettings->logoUrl() }}" alt="" class="brand-logo">
                @else
                    <span class="brand-mark">
                        <x-application-logo class="fill-current" />
                    </span>
                @endif
                <span class="brand-name truncate" :class="$store.sidebar.collapsed ? 'md:hidden' : ''">
                    {{ $generalSettings->business_name ?? config('app.name') }}
                </span>
            </a>
            <button type="button" x-on:click="$store.sidebar.open = false" class="brand-close md:hidden p-1 -mr-1">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
            @php $lastSection = null; @endphp

            @foreach ($items as $item)
                @if (($item['section'] ?? null) && $item['section'] !== $lastSection)
                    @php $lastSection = $item['section']; @endphp
                    <div class="nav-section-label" :class="$store.sidebar.collapsed ? 'md:hidden' : ''">{{ $lastSection }}</div>
                @endif

                @php $isLinkable = Route::has($item['route']); @endphp

                @if ($isLinkable)
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        x-on:click="$store.sidebar.open = false"
                        title="{{ $item['label'] }}"
                        @class([
                            'nav-item',
                            'active' => request()->routeIs($item['route']),
                        ])
                        :class="$store.sidebar.collapsed ? 'md:justify-center md:px-2' : ''"
                    >
                        <span class="icon-chip"><x-icon :name="$item['icon']" /></span>
                        <span :class="$store.sidebar.collapsed ? 'md:hidden' : ''">{{ $item['label'] }}</span>
                    </a>
                @else
                    <span
                        title="{{ $item['label'] }}"
                        class="nav-item disabled"
                        :class="$store.sidebar.collapsed ? 'md:justify-center md:px-2' : ''"
                    >
                        <span class="icon-chip"><x-icon :name="$item['icon']" /></span>
                        <span :class="$store.sidebar.collapsed ? 'md:hidden' : ''">{{ $item['label'] }}</span>
                    </span>
                @endif
            @endforeach
        </nav>
    </aside>
</div>
