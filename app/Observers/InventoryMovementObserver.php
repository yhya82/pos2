<?php

namespace App\Observers;

use App\Events\StockChanged;
use App\Models\InventoryMovement;

/**
 * Every stock-changing service (SaleService, InventoryAdjustmentService,
 * ManualStockService, PurchaseReceivingService, ReturnService) already
 * writes one InventoryMovement row per change — observing this one model is
 * a single hook covering all five, instead of adding a broadcast call to
 * each service individually.
 */
class InventoryMovementObserver
{
    public function created(InventoryMovement $movement): void
    {
        event(new StockChanged($movement));
    }
}
