<?php

namespace Tests\Feature\Services;

use App\Models\Product;
use App\Models\User;
use App\Services\ManualStockService;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Unlike PurchaseReceivingService and BatchCostService, this service had
 * no negative-cost guard at all — a direct call with a negative unit cost
 * was silently accepted and wrote a batch with a negative unit_cost.
 */
class ManualStockServiceTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_a_negative_cost_is_refused(): void
    {
        $product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6]);
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A cost cannot be negative.');

        app(ManualStockService::class)->receive(
            product: $product,
            quantity: 10,
            quantityUnit: 'selling',
            unitCost: -1,
            receivedDate: now()->toDateString(),
            expiryDate: null,
            batchCode: null,
            reason: null,
            user: $user,
        );
    }

    public function test_a_zero_cost_is_still_allowed(): void
    {
        $product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6]);
        $user = User::factory()->create();

        $batch = app(ManualStockService::class)->receive(
            product: $product,
            quantity: 10,
            quantityUnit: 'selling',
            unitCost: 0,
            receivedDate: now()->toDateString(),
            expiryDate: null,
            batchCode: null,
            reason: null,
            user: $user,
        );

        $this->assertSame(0.0, (float) $batch->unit_cost);
    }
}
