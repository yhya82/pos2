<div>
    {{-- Mobile backdrop — only ever relevant below md, where the sidebar
         becomes a slide-over instead of the always-visible desktop rail. --}}
    <div
        x-show="sidebarOpen"
        x-transition.opacity
        x-on:click="sidebarOpen = false"
        class="fixed inset-0 bg-gray-900/50 z-30 md:hidden"
        style="display: none;"
    ></div>

    <aside
        class="fixed inset-y-0 left-0 z-40 w-64 flex flex-col border-e border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800 transition-transform duration-200 ease-in-out md:translate-x-0"
        :class="sidebarOpen ? 'translate-x-0' : '-translate-x-full'"
    >
        <div class="flex items-center justify-between h-16 px-6 border-b border-gray-200 dark:border-gray-700 shrink-0">
            <a href="{{ route('dashboard') }}" wire:navigate x-on:click="sidebarOpen = false" class="flex items-center gap-2 min-w-0">
                @if ($generalSettings?->logoUrl())
                    <img src="{{ $generalSettings->logoUrl() }}" alt="" class="h-8 w-8 rounded-md object-cover shrink-0">
                @else
                    <x-application-logo class="h-8 w-8 fill-current text-gray-800 dark:text-gray-200 shrink-0" />
                @endif
                <span class="font-semibold text-gray-800 dark:text-gray-100 truncate">
                    {{ $generalSettings->business_name ?? config('app.name') }}
                </span>
            </a>
            <button type="button" x-on:click="sidebarOpen = false" class="md:hidden p-1 -mr-1 text-gray-400 hover:text-gray-600 dark:hover:text-gray-200">
                <svg xmlns="http://www.w3.org/2000/svg" fill="none" viewBox="0 0 24 24" stroke-width="1.5" stroke="currentColor" class="h-5 w-5">
                    <path stroke-linecap="round" stroke-linejoin="round" d="M6 18L18 6M6 6l12 12" />
                </svg>
            </button>
        </div>

        <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
            @foreach ($items as $item)
                @php $isLinkable = Route::has($item['route']); @endphp

                @if ($isLinkable)
                    <a
                        href="{{ route($item['route']) }}"
                        wire:navigate
                        x-on:click="sidebarOpen = false"
                        @class([
                            'flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium transition',
                            'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' => request()->routeIs($item['route']),
                            'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' => ! request()->routeIs($item['route']),
                        ])
                    >
                        <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                        {{ $item['label'] }}
                    </a>
                @else
                    <span class="flex items-center gap-2.5 rounded-md px-3 py-2 text-sm font-medium text-gray-400 dark:text-gray-600 cursor-default">
                        <x-icon :name="$item['icon']" class="h-5 w-5 shrink-0" />
                        {{ $item['label'] }}
                    </span>
                @endif
            @endforeach
        </nav>
    </aside>
</div>
