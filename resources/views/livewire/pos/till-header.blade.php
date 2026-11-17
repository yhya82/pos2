<div class="header-widgets">
    <span class="brand">{{ __('POS') }}</span>

    @if ($canViewSalesHistory)
        <a href="{{ route('sales.index') }}" wire:navigate class="link">View sales</a>
    @endif

    <span class="today">{{ __('Today\'s sales') }}: <b>{{ number_format($todaysSalesTotal, 2) }}</b></span>
</div>
