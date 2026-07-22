<aside class="hidden md:flex md:w-64 md:flex-col md:fixed md:inset-y-0 border-e border-gray-200 dark:border-gray-700 bg-white dark:bg-gray-800">
    <div class="flex items-center h-16 px-6 border-b border-gray-200 dark:border-gray-700 shrink-0">
        <a href="{{ route('dashboard') }}" wire:navigate class="flex items-center gap-2">
            <x-application-logo class="h-8 w-8 fill-current text-gray-800 dark:text-gray-200" />
            <span class="font-semibold text-gray-800 dark:text-gray-100">
                {{ \App\Models\GeneralSetting::current()->business_name ?? config('app.name') }}
            </span>
        </a>
    </div>

    <nav class="flex-1 overflow-y-auto py-4 px-3 space-y-1">
        @foreach ($items as $item)
            @php $isLinkable = Route::has($item['route']); @endphp

            @if ($isLinkable)
                <a
                    href="{{ route($item['route']) }}"
                    wire:navigate
                    @class([
                        'block rounded-md px-3 py-2 text-sm font-medium transition',
                        'bg-indigo-50 text-indigo-700 dark:bg-indigo-900/40 dark:text-indigo-300' => request()->routeIs($item['route']),
                        'text-gray-600 hover:bg-gray-100 hover:text-gray-900 dark:text-gray-300 dark:hover:bg-gray-700 dark:hover:text-white' => ! request()->routeIs($item['route']),
                    ])
                >
                    {{ $item['label'] }}
                </a>
            @else
                <span class="block rounded-md px-3 py-2 text-sm font-medium text-gray-400 dark:text-gray-600 cursor-default">
                    {{ $item['label'] }}
                </span>
            @endif
        @endforeach
    </nav>
</aside>
