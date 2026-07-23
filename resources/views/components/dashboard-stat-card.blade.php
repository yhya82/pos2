@props([
    'icon',
    'iconClass' => 'bg-gray-50 dark:bg-gray-900/40 text-gray-600 dark:text-gray-400',
    'accent' => 'bg-gray-300 dark:bg-gray-600',
    'label',
    'valueClass' => 'text-gray-900 dark:text-gray-100',
])

<div {{ $attributes->merge(['class' => 'group relative bg-white dark:bg-gray-800 rounded-xl shadow-sm ring-1 ring-gray-900/5 dark:ring-white/10 hover:shadow-md transition-shadow p-5 overflow-hidden']) }}>
    <div class="absolute inset-x-0 top-0 h-1 {{ $accent }}"></div>

    <div class="flex items-start gap-4">
        <div class="shrink-0 rounded-xl p-2.5 {{ $iconClass }}">
            <x-icon :name="$icon" class="h-5 w-5" />
        </div>
        <div class="min-w-0 flex-1">
            <div class="text-xs font-medium uppercase tracking-wide text-gray-500 dark:text-gray-400">{{ $label }}</div>
            <div class="mt-1 text-2xl font-bold tabular-nums {{ $valueClass }}">{{ $slot }}</div>
            @isset($footer)
                <div class="mt-1.5 text-xs text-gray-400 dark:text-gray-500">{{ $footer }}</div>
            @endisset
        </div>
    </div>
</div>
