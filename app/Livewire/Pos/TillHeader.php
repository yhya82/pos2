<?php

namespace App\Livewire\Pos;

use App\Models\Sale;
use Livewire\Attributes\On;
use Livewire\Component;

/**
 * The small widgets that sit in the POS page's own header slot: today's
 * takings, and a link to the real Sales page. Deliberately a separate
 * component from the big cart Terminal, since neither touches the cart and
 * this already has a cheap, live data source (SaleCompleted) — the same
 * pattern NotificationBell already uses for its own header widget.
 *
 * Stock alerts are deliberately NOT duplicated here — the app already has
 * one notification system (low stock included, via the real
 * NotificationBell already in the shared header on every page); this
 * doesn't need a second, POS-only bell reading the same thing twice.
 */
class TillHeader extends Component
{
    /**
     * Re-queries today's total/list the moment any sale completes — no
     * payload needed, this is a "something changed, re-render" signal like
     * every other live listener in this app.
     */
    #[On('echo-private:sales,.SaleCompleted')]
    public function onSaleCompleted(): void
    {
        //
    }

    public function render()
    {
        $user = auth()->user();

        // Scoped exactly like Sales History and every other sales screen —
        // a cashier's "today's sales" is their own takings, not the store's.
        $todaysSalesTotal = Sale::visibleTo($user)
            ->whereDate('sale_date', now()->toDateString())
            ->where('status', 'completed')
            ->sum('total_amount');

        return view('livewire.pos.till-header', [
            'canViewSalesHistory' => $user->hasPermission('sales', 'view'),
            'todaysSalesTotal' => (float) $todaysSalesTotal,
        ]);
    }
}
