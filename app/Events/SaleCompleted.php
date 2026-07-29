<?php

namespace App\Events;

use App\Models\Sale;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired from SaleService::completeSale() once the transaction has actually
 * committed (never from inside it) — unlike notifications, a sale is
 * created directly by this app's own request code, not a DB trigger, so
 * there's no polling watcher needed here.
 *
 * No-op payload by design: DashboardOverview and SalesHistory both already
 * recompute everything fresh on render(), each respecting the viewer's own
 * filters/permissions. Shipping the sale's numbers over the wire would just
 * be a second, driftable copy of that same logic.
 */
class SaleCompleted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public Sale $sale)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('dashboard'),
            new PrivateChannel('sales'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SaleCompleted';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'sale_id' => $this->sale->id,
            'receipt_number' => $this->sale->receipt_number,
        ];
    }
}
