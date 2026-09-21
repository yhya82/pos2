<?php

namespace Tests\Feature;

use App\Livewire\Inventory\InventoryOverview;
use App\Livewire\Products\ProductManager;
use App\Livewire\Products\ProductProfile;
use App\Livewire\PurchaseOrders\PurchaseOrderManager;
use App\Livewire\Returns\ReturnManager;
use App\Livewire\Settings\SettingsManager;
use App\Models\Batch;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesReturn;
use App\Models\SalesSetting;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\InventoryAdjustmentService;
use App\Services\ManualStockService;
use App\Services\PurchaseReceivingService;
use App\Services\ReturnService;
use App\Services\SaleService;
use App\Support\Whole;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Every quantity someone can enter — stock in, adjustments, order lines,
 * deliveries, returns, conversion, minimum stock — is a whole number.
 * Stock already holding a fraction is left alone; the rule is for what's entered.
 */
class WholeQuantitiesTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->product = Product::factory()->create([
            'selling_price' => 10, 'cost_price' => 6, 'conversion_qty' => 1,
            'category_id' => Category::create(['name' => 'Whole Cat', 'status' => 'active'])->id,
            'supplier_id' => Supplier::create(['name' => 'Whole Sup', 'status' => 'active'])->id,
        ]);
    }

    // -------------------------------------------------------------- the rule

    public function test_what_counts_as_a_whole_number(): void
    {
        foreach ([3, 3.0, '3', '3.0', '3.000', 0, '0', 1000000] as $ok) {
            $this->assertTrue(Whole::is($ok), var_export($ok, true).' should count');
        }

        foreach ([2.5, '2.5', '0.001', 'abc', '', null, '1,5', '3e', [1]] as $bad) {
            $this->assertFalse(Whole::is($bad), var_export($bad, true).' should not count');
        }
    }

    public function test_assert_names_the_thing_and_the_value(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The quantity received must be a whole number of 1 or more (1, 2, 3…) — it was 2.5.');

        Whole::assert(2.5, 'The quantity received', 1);
    }

    // ------------------------------------------------------------- add stock

    private function addStock(string $qty)
    {
        return Livewire::actingAs($this->admin)->test(ProductProfile::class, ['product' => $this->product])
            ->call('openAddStockForm')
            ->set('addStockQty', $qty)
            ->call('submitAddStock');
    }

    public function test_add_stock_takes_whole_numbers_only(): void
    {
        foreach (['2.5', '0', '-3', 'abc', '0.5'] as $bad) {
            $this->addStock($bad)->assertHasErrors('addStockQty');
        }

        $this->assertSame(0, Batch::where('product_id', $this->product->id)->count());

        $this->addStock('12')->assertHasNoErrors();
        $this->addStock('3.0')->assertHasNoErrors();   // three, however it's typed

        $this->assertEquals(15, Batch::where('product_id', $this->product->id)->sum('qty_received'));
    }

    public function test_the_message_is_plain_english(): void
    {
        $this->addStock('2.5')->assertHasErrors('addStockQty')
            ->assertSee('Enter a whole number, like 3');
    }

    public function test_the_stock_service_refuses_a_fraction_whatever_called_it(): void
    {
        $this->expectException(RuntimeException::class);

        app(ManualStockService::class)->receive($this->product, 2.5, 'selling', 5, now()->toDateString(), null, null, null, $this->admin);
    }

    // ------------------------------------------------------------ adjustments

    public function test_adjust_stock_takes_whole_numbers_only(): void
    {
        $batch = Batch::factory()->for($this->product)->remaining(20)->create(['unit_cost' => 5]);

        $component = Livewire::actingAs($this->admin)->test(ProductProfile::class, ['product' => $this->product])
            ->set('adjustBatchId', $batch->id)
            ->set('adjustType', InventoryAdjustmentService::TYPE_CORRECTION_REMOVE)
            ->set('adjustReason', 'recount');

        $component->set('adjustQty', '1.5')->call('submitAdjustment')->assertHasErrors('adjustQty');
        $this->assertEquals(20, $batch->fresh()->qty_remaining);

        $component->set('adjustQty', '2')->call('submitAdjustment')->assertHasNoErrors();
        $this->assertEquals(18, $batch->fresh()->qty_remaining);
    }

    public function test_the_inventory_screens_adjustment_is_whole_numbers_only_too(): void
    {
        $batch = Batch::factory()->for($this->product)->remaining(20)->create(['unit_cost' => 5]);

        Livewire::actingAs($this->admin)->test(InventoryOverview::class)
            ->set('adjustBatchId', $batch->id)
            ->set('adjustType', InventoryAdjustmentService::TYPE_CORRECTION_REMOVE)
            ->set('adjustReason', 'recount')
            ->set('adjustQty', '0.5')
            ->call('submitAdjustment')
            ->assertHasErrors('adjustQty');

        $this->assertEquals(20, $batch->fresh()->qty_remaining);
    }

    public function test_the_adjustment_service_refuses_a_fraction(): void
    {
        $batch = Batch::factory()->for($this->product)->remaining(20)->create(['unit_cost' => 5]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('The adjustment quantity must be a whole number');

        app(InventoryAdjustmentService::class)->adjust($batch, InventoryAdjustmentService::TYPE_CORRECTION_REMOVE, 1.5, 'x', $this->admin);
    }

    public function test_stock_already_holding_a_fraction_can_still_be_adjusted_by_whole_units(): void
    {
        $batch = Batch::factory()->for($this->product)->remaining(2.5)->create(['unit_cost' => 5, 'qty_received' => 5]);

        app(InventoryAdjustmentService::class)->adjust($batch, InventoryAdjustmentService::TYPE_CORRECTION_REMOVE, 1, 'legacy count', $this->admin);

        $this->assertEquals(1.5, $batch->fresh()->qty_remaining);
    }

    // ------------------------------------------------------------ product form

    public function test_conversion_and_minimum_stock_are_whole_numbers(): void
    {
        Livewire::actingAs($this->admin)->test(ProductProfile::class, ['product' => $this->product])
            ->call('openEditForm')
            ->set('conversionQty', '2.5')
            ->set('minStockLevel', '1.5')
            ->call('submitEdit')
            ->assertHasErrors(['conversionQty', 'minStockLevel']);

        Livewire::actingAs($this->admin)->test(ProductProfile::class, ['product' => $this->product])
            ->call('openEditForm')
            ->set('conversionQty', '12')
            ->set('minStockLevel', '5')
            ->call('submitEdit')
            ->assertHasNoErrors();

        $this->assertEquals(12, $this->product->fresh()->conversion_qty);
        $this->assertEquals(5, $this->product->fresh()->min_stock_level);
    }

    public function test_the_new_product_form_starts_from_whole_numbers_and_refuses_fractions(): void
    {
        $unit = Unit::factory()->create();

        Livewire::actingAs($this->admin)->test(ProductManager::class)
            ->call('create')
            ->set('name', 'Fraction Widget')
            ->set('categoryId', Category::firstOrCreate(['name' => 'Whole Cat'], ['status' => 'active'])->id)
            ->set('supplierId', Supplier::firstOrCreate(['name' => 'Whole Sup'], ['status' => 'active'])->id)
            ->set('purchaseUnitId', $unit->id)->set('sellingUnitId', $unit->id)
            ->set('sellingPrice', '10')->set('costPrice', '6')
            ->set('conversionQty', '1.5')
            ->set('minStockLevel', '0.5')
            ->call('save')
            ->assertHasErrors(['conversionQty', 'minStockLevel']);

        $this->assertDatabaseMissing('products', ['name' => 'Fraction Widget']);
    }

    // ---------------------------------------------------------- purchase orders

    public function test_an_order_line_takes_whole_quantities_only(): void
    {
        $supplier = Supplier::firstOrCreate(['name' => 'Whole Sup'], ['status' => 'active']);

        $component = Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('create')
            ->set('supplierId', $supplier->id)
            ->set('lines.0.product_id', $this->product->id);

        $component->set('lines.0.qty_ordered', '2.5')->call('save')->assertHasErrors('lines.0.qty_ordered');
        $this->assertSame(0, PurchaseOrder::count());

        $component->set('lines.0.qty_ordered', '10')->call('save')->assertHasNoErrors();
        $this->assertSame(1, PurchaseOrder::count());
    }

    private function orderedOf(int $qty): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => $this->product->supplier_id,
            'status' => 'ordered', 'order_date' => now()->toDateString(), 'created_by' => $this->admin->id,
        ]);
        $po->lineItems()->create(['product_id' => $this->product->id, 'qty_ordered' => $qty, 'purchase_unit_id' => $this->product->purchase_unit_id, 'cost_price' => 6]);

        return $po;
    }

    public function test_receiving_takes_whole_quantities_for_good_and_damaged(): void
    {
        $po = $this->orderedOf(10);

        foreach ([['qty' => '2.5', 'damaged_qty' => ''], ['qty' => '', 'damaged_qty' => '1.5'], ['qty' => '3', 'damaged_qty' => '0.5']] as $bad) {
            Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
                ->call('openReceive', $po->id)
                ->set('receivingLines.0.qty', $bad['qty'])
                ->set('receivingLines.0.damaged_qty', $bad['damaged_qty'])
                ->set('receivingLines.0.damaged_reason', 'x')
                ->call('receive')
                ->assertDispatched('flash-message', message: 'Quantities must be whole numbers, like 3 — no decimals.', variant: 'error');
        }

        $this->assertSame(0, Batch::where('product_id', $this->product->id)->count());
    }

    public function test_the_receiving_service_refuses_fractions_even_in_pieces(): void
    {
        $po = $this->orderedOf(10);
        $line = $po->lineItems()->first();

        foreach ([['qty' => 2.5], ['qty' => 2, 'damaged_qty' => 0.5, 'damaged_reason' => 'x'], ['qty' => 2.5, 'qty_unit' => 'selling']] as $bad) {
            try {
                app(PurchaseReceivingService::class)->receive($po, [['line_item_id' => $line->id] + $bad], $this->admin);
                $this->fail('expected refusal for '.json_encode($bad));
            } catch (RuntimeException $e) {
                $this->assertStringContainsString('must be a whole number', $e->getMessage());
            }
        }

        $this->assertSame(0, Batch::count());
        $this->assertEquals(0, $line->fresh()->qty_received);
    }

    public function test_whole_deliveries_still_work_in_packets_and_in_pieces(): void
    {
        $unitP = Unit::factory()->create(['name' => 'packet']);
        $unitS = Unit::factory()->create(['name' => 'piece']);
        $this->product->update(['purchase_unit_id' => $unitP->id, 'selling_unit_id' => $unitS->id, 'conversion_qty' => 12]);
        $po = $this->orderedOf(2);
        $line = $po->lineItems()->first();

        app(PurchaseReceivingService::class)->receive($po, [['line_item_id' => $line->id, 'qty' => 1]], $this->admin);                      // 1 packet = 12 pieces
        app(PurchaseReceivingService::class)->receive($po->fresh(), [['line_item_id' => $line->id, 'qty' => 10, 'qty_unit' => 'selling', 'damaged_qty' => 2, 'damaged_unit' => 'selling', 'damaged_reason' => 'x']], $this->admin);

        $this->assertEquals(22, Batch::where('product_id', $this->product->id)->sum('qty_received'));
        $this->assertSame('received', $po->fresh()->status);
    }

    // ----------------------------------------------------------------- returns

    private function completedSale(): \App\Models\Sale
    {
        Batch::factory()->for($this->product)->remaining(50)->create(['unit_cost' => 5]);

        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $this->product->id, 'quantity' => 4]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none', discountValue: 0, discountReason: null,
            cashier: $this->admin,
        );
    }

    public function test_a_return_takes_whole_quantities_only(): void
    {
        $sale = $this->completedSale();

        Livewire::actingAs($this->admin)->test(ReturnManager::class)
            ->call('startProcessing')
            ->call('selectSale', $sale->id)
            ->set('returnLines.0.quantity', '1.5')
            ->set('overallReason', 'x')
            ->call('submitReturn')
            ->assertHasErrors('returnLines.0.quantity');

        $this->assertSame(0, SalesReturn::count());
    }

    public function test_the_return_service_refuses_a_fraction(): void
    {
        $sale = $this->completedSale();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('A returned quantity must be a whole number');

        app(ReturnService::class)->processReturn($sale, [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 1.5, 'condition_type' => 'sellable']], 'x', $this->admin);
    }

    public function test_a_whole_return_still_works(): void
    {
        $sale = $this->completedSale();

        $return = app(ReturnService::class)->processReturn($sale, [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 2, 'condition_type' => 'sellable']], 'x', $this->admin);

        $this->assertNotNull($return->id);
    }

    // --------------------------------------------------------------- settings

    public function test_the_low_stock_default_is_a_whole_number(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('inventory.low_stock_default_threshold', '7.5')
            ->call('saveInventory')
            ->assertHasErrors('inventory.low_stock_default_threshold');
    }

    public function test_the_max_discount_setting_is_gone_from_settings_and_saving_leaves_it_alone(): void
    {
        $before = (string) SalesSetting::current()->max_discount_percentage;

        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->assertDontSee('Max Discount Percentage')
            ->assertDontSee('a cashier can\'t apply more than this', false)
            ->call('saveSales')
            ->assertHasNoErrors();

        $this->assertSame($before, (string) SalesSetting::current()->fresh()->max_discount_percentage, 'the old stored value is left as it was');
    }
}
