<?php

namespace App\Events;

use Illuminate\Broadcasting\Channel;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Throwable;

/**
 * "Some product's price or promo just changed" — open POS terminals listen
 * for this on the same private `stock` channel as StockChanged and re-pull
 * their product list (Terminal::onLiveDataChanged), so cashiers see new
 * prices without refreshing the page.
 *
 * Fired by ProductObserver for any single product edit, once by the bulk
 * discount actions for a whole batch, and by `pos:refresh-prices` at
 * midnight UTC — a promo that starts or ends by date has no edit to hook.
 *
 * The payload is informational only; receivers re-fetch everything.
 */
class ProductPriceChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets;

    /**
     * @param  array<int, int>  $productIds  Empty means "prices may have changed, product unknown".
     */
    public function __construct(public array $productIds = [])
    {
    }

    /**
     * Dispatch without ever failing the caller. This is a live-refresh
     * nicety — if the websocket server is down, saving a price must still
     * work (tills just fall back to needing a page refresh).
     *
     * @param  array<int, int>  $productIds
     */
    public static function dispatchSafely(array $productIds = []): void
    {
        try {
            static::dispatch($productIds);
        } catch (Throwable $e) {
            report($e);
        }
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
        return 'ProductPriceChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return ['product_ids' => $this->productIds];
    }
}
