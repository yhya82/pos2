<?php

namespace App\Events;

use App\Models\InventoryMovement;
use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;

/**
 * Fired by InventoryMovementObserver::created() — every stock-changing
 * service (sales, manual adjustments, PO receiving, returns) already writes
 * an InventoryMovement row, so observing that one model is a single hook
 * covering all of them instead of a broadcast call duplicated into each
 * service.
 *
 * No-op payload: InventoryOverview, the POS terminal's product grid, and
 * the dashboard's stock cards all already recompute current stock fresh on
 * render() — this just needs to trigger that re-render.
 */
class StockChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    public function __construct(public InventoryMovement $movement)
    {
    }

    /**
     * @return array<int, Channel>
     */
    public function broadcastOn(): array
    {
        return [new PrivateChannel('stock')];
    }

    public function broadcastAs(): string
    {
        return 'StockChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'product_id' => $this->movement->product_id,
            'movement_type' => $this->movement->movement_type,
        ];
    }
}
