<?php

namespace App\Observers;

use App\Events\ProductPriceChanged;
use App\Models\Product;

/**
 * One hook for every path that can change what a product sells for — the
 * product edit form, the profile's discount form, anything added later —
 * instead of a broadcast call copied into each. Bulk paths that touch many
 * products wrap their updates in Product::withoutEvents() and send a single
 * ProductPriceChanged themselves.
 */
class ProductObserver
{
    /**
     * Broadcast only once the transaction has committed, so a till that
     * re-fetches immediately can't read the old price.
     */
    public bool $afterCommit = true;

    /** @var array<int, string> */
    private const PRICE_FIELDS = [
        'selling_price',
        'promo_discount_type',
        'promo_discount_value',
        'promo_starts_at',
        'promo_ends_at',
    ];

    public function updated(Product $product): void
    {
        if ($product->wasChanged(self::PRICE_FIELDS)) {
            ProductPriceChanged::dispatchSafely([$product->id]);
        }
    }
}
