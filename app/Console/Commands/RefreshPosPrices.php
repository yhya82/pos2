<?php

namespace App\Console\Commands;

use App\Events\ProductPriceChanged;
use Illuminate\Console\Command;

/**
 * A promo with a start or end date changes what a product sells for at
 * midnight without anyone editing anything, so ProductObserver never fires.
 * This tells open POS terminals to re-pull prices right as the day rolls
 * over (UTC — the same day boundary Product::hasActivePromo() and
 * v_inventory_valuation use).
 */
class RefreshPosPrices extends Command
{
    protected $signature = 'pos:refresh-prices';

    protected $description = 'Tell open POS terminals to refresh product prices (date-based promos flip at midnight)';

    public function handle(): int
    {
        // Not the "safe" dispatch on purpose — in a scheduled job, a failed
        // broadcast should show up as a failed run, not vanish silently.
        ProductPriceChanged::dispatch();

        $this->info('Price refresh broadcast sent.');

        return self::SUCCESS;
    }
}
