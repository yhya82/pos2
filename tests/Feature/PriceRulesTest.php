<?php

namespace Tests\Feature;

use App\Livewire\Inventory\InventoryOverview;
use App\Livewire\Products\ProductManager;
use App\Livewire\Products\ProductProfile;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\Category;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\SalesSetting;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\BatchCostService;
use App\Services\ManualStockService;
use App\Services\PurchaseReceivingService;
use App\Services\SaleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Business rules for prices: a selling price is above zero, cost never
 * exceeds price, promotions and discounts never push a unit below its cost,
 * and a batch received at the wrong cost can be corrected.
 */
class PriceRulesTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private function product(array $attrs = []): Product
    {
        return Product::factory()->create(array_merge(['selling_price' => 10, 'cost_price' => 6], $attrs));
    }

    private function fillProductForm($component, float|string $selling, float|string $cost)
    {
        $unit = Unit::factory()->create();

        return $component
            ->set('name', 'Rule Widget')
            ->set('categoryId', Category::firstOrCreate(['name' => 'Cat'], ['status' => 'active'])->id)
            ->set('supplierId', Supplier::firstOrCreate(['name' => 'Sup'], ['status' => 'active'])->id)
            ->set('purchaseUnitId', $unit->id)
            ->set('sellingUnitId', $unit->id)
            ->set('conversionQty', '1')
            ->set('sellingPrice', (string) $selling)
            ->set('costPrice', (string) $cost)
            ->set('minStockLevel', '0');
    }

    // ---------------------------------------------------------------- forms

    public function test_the_product_form_rejects_a_zero_or_negative_selling_price(): void
    {
        $admin = User::factory()->create();

        foreach (['0', '-5'] as $bad) {
            $component = Livewire::actingAs($admin)->test(ProductManager::class)->call('create');
            $this->fillProductForm($component, $bad, '0')->call('save')->assertHasErrors(['sellingPrice']);
        }

        $this->assertDatabaseMissing('products', ['name' => 'Rule Widget']);
    }

    public function test_the_product_form_rejects_a_cost_above_the_selling_price(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(ProductManager::class)->call('create');

        $this->fillProductForm($component, '10', '10.01')->call('save')->assertHasErrors(['costPrice' => 'lt']);
        $this->assertDatabaseMissing('products', ['name' => 'Rule Widget']);
    }

    public function test_a_cost_equal_to_the_selling_price_is_refused(): void
    {
        $component = Livewire::actingAs(User::factory()->create())->test(ProductManager::class)->call('create');

        $this->fillProductForm($component, '10', '10')->call('save')->assertHasErrors(['costPrice' => 'lt']);
        $this->assertDatabaseMissing('products', ['name' => 'Rule Widget']);
    }

    public function test_the_database_itself_refuses_a_cost_equal_to_the_price(): void
    {
        $product = $this->product();

        $this->expectException(\Illuminate\Database\QueryException::class);
        DB::table('products')->where('id', $product->id)->update(['cost_price' => $product->selling_price]);
    }

    public function test_receiving_at_exactly_the_selling_price_warns_and_keeps_the_old_reference_cost(): void
    {
        $product = $this->product(['conversion_qty' => 1]);   // price 10, cost 6
        [$po, $line, $user] = $this->orderFor($product, 10);

        $warnings = app(PurchaseReceivingService::class)->receive($po, [['line_item_id' => $line->id, 'qty' => 5]], $user);

        $this->assertCount(1, $warnings);
        $this->assertEquals(10, Batch::where('product_id', $product->id)->value('unit_cost'));
        $this->assertEquals(6, $product->fresh()->cost_price);
    }

    public function test_editing_a_price_that_leaves_an_existing_promo_below_cost_is_rejected(): void
    {
        // 6.00 cost; 20% off 10.00 = 8.00. Dropping the price to 7.00 makes the promo 5.60 < 6.00.
        $product = $this->product([
            'promo_discount_type' => 'percentage',
            'promo_discount_value' => 20,
            // The edit form requires these, and would fail on them before reaching the price rules.
            'category_id' => Category::create(['name' => 'Edit Cat', 'status' => 'active'])->id,
            'supplier_id' => Supplier::create(['name' => 'Edit Sup', 'status' => 'active'])->id,
        ]);

        Livewire::actingAs(User::factory()->create())->test(ProductProfile::class, ['product' => $product])
            ->call('openEditForm')
            ->set('sellingPrice', '7')
            ->call('submitEdit')
            ->assertHasErrors(['sellingPrice']);

        $this->assertEquals(10, $product->fresh()->selling_price);
    }

    // ------------------------------------------------------ database backstop

    public function test_the_database_itself_refuses_a_cost_above_the_price_or_a_zero_price(): void
    {
        foreach ([['selling_price' => 10, 'cost_price' => 11], ['selling_price' => 0, 'cost_price' => 0]] as $attrs) {
            try {
                Product::factory()->create($attrs);
                $this->fail('Expected the database to reject '.json_encode($attrs));
            } catch (QueryException $e) {
                $this->assertStringContainsString('chk_products', $e->getMessage());
            }
        }
    }

    // ------------------------------------------------------------- promotions

    public function test_a_promotion_cannot_price_the_product_below_its_cost(): void
    {
        $product = $this->product();   // cost 6, price 10

        $component = Livewire::actingAs(User::factory()->create())->test(ProductProfile::class, ['product' => $product])
            ->set('promoDiscountType', 'percentage')
            ->set('promoDiscountValue', '50');   // -> 5.00 < 6.00

        $component->call('saveDiscount')->assertHasErrors(['promoDiscountValue']);
        $this->assertSame('none', $product->fresh()->promo_discount_type);

        $component->set('promoDiscountValue', '40')->call('saveDiscount')->assertHasNoErrors();   // -> 6.00, exactly cost
        $this->assertSame('percentage', $product->fresh()->promo_discount_type);
    }

    public function test_a_promotion_is_held_against_the_dearest_batch_still_in_stock_not_just_the_product_cost(): void
    {
        $product = $this->product();   // reference cost 6
        Batch::factory()->for($product)->remaining(5)->create(['unit_cost' => 9]);

        Livewire::actingAs(User::factory()->create())->test(ProductProfile::class, ['product' => $product])
            ->set('promoDiscountType', 'percentage')
            ->set('promoDiscountValue', '20')   // 8.00: fine vs cost 6, below the 9.00 batch
            ->call('saveDiscount')
            ->assertHasErrors(['promoDiscountValue']);
    }

    // ---------------------------------------------------------- bulk discounts

    public function test_a_bulk_discount_that_takes_any_product_below_cost_applies_to_none(): void
    {
        $fine = $this->product(['name' => 'Fine Item']);                              // 10 / cost 6
        $tight = $this->product(['name' => 'Tight Item', 'selling_price' => 10, 'cost_price' => 9]);

        Livewire::actingAs(User::factory()->create())->test(InventoryOverview::class)
            ->set('selectedProductIds', [$fine->id, $tight->id])
            ->set('bulkDiscountType', 'percentage')
            ->set('bulkDiscountValue', '20')   // fine: 8.00 ok; tight: 8.00 < 9.00
            ->call('applyBulkDiscount')
            ->assertHasErrors(['bulkDiscountValue']);

        $this->assertSame('none', $fine->fresh()->promo_discount_type, 'the safe product must not be half-applied');
        $this->assertSame('none', $tight->fresh()->promo_discount_type);
    }

    public function test_a_safe_bulk_discount_applies(): void
    {
        $a = $this->product();
        $b = $this->product();

        Livewire::actingAs(User::factory()->create())->test(InventoryOverview::class)
            ->set('selectedProductIds', [$a->id, $b->id])
            ->set('bulkDiscountType', 'percentage')
            ->set('bulkDiscountValue', '20')
            ->call('applyBulkDiscount')
            ->assertHasNoErrors();

        $this->assertSame('percentage', $a->fresh()->promo_discount_type);
        $this->assertSame('percentage', $b->fresh()->promo_discount_type);
    }

    // -------------------------------------------------------------------- POS

    private function sell(Product $product, float $qty, string $discountType = 'none', float $discountValue = 0)
    {
        SalesSetting::current()->update(['max_discount_percentage' => 100]);

        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => $qty]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: $discountType,
            discountValue: $discountValue,
            discountReason: $discountType === 'none' ? null : 'test',
            cashier: User::factory()->create(),
        );
    }

    public function test_a_cashier_discount_below_the_batch_cost_is_refused(): void
    {
        $product = $this->product();
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 8]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('below its cost');

        // 5 units = 50.00; 15.00 off -> 7.00 each < 8.00 cost.
        $this->sell($product, 5, 'fixed', 15);
    }

    public function test_a_cashier_discount_that_stays_at_or_above_cost_is_allowed(): void
    {
        $product = $this->product();
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 8]);

        $sale = $this->sell($product, 5, 'fixed', 10);   // 8.00 each = exactly cost

        $this->assertSame('completed', $sale->status);
    }

    public function test_a_promo_line_priced_below_a_dear_batch_is_refused_at_the_till(): void
    {
        $product = $this->product(['promo_discount_type' => 'percentage', 'promo_discount_value' => 20]);   // 8.00
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 9]);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('below its cost');

        $this->sell($product, 2);
    }

    public function test_an_undiscounted_sale_is_not_blocked_by_a_dear_batch(): void
    {
        // The rule is about discounts, not legacy stock priced badly.
        $product = $this->product();
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 12]);

        $this->assertSame('completed', $this->sell($product, 2)->status);
    }

    // -------------------------------------------------------------- receiving

    private function orderFor(Product $product, float $lineCost): array
    {
        $user = User::factory()->create();
        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => Supplier::create(['name' => 'Recv Sup', 'status' => 'active'])->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
        $line = $po->lineItems()->create([
            'product_id' => $product->id,
            'qty_ordered' => 10,
            'purchase_unit_id' => $product->purchase_unit_id,
            'cost_price' => $lineCost,
        ]);

        return [$po, $line, $user];
    }

    public function test_receiving_can_override_the_cost_paid_and_set_a_new_selling_price(): void
    {
        $product = $this->product(['conversion_qty' => 1]);   // price 10, cost 6
        [$po, $line, $user] = $this->orderFor($product, 6);   // ordered at 6

        app(PurchaseReceivingService::class)->receive($po, [[
            'line_item_id' => $line->id, 'qty' => 5, 'unit_cost' => '8', 'selling_price' => '13',
        ]], $user);

        $product = $product->fresh();
        $this->assertEquals(8, Batch::where('product_id', $product->id)->value('unit_cost'), 'batch takes the cost actually paid');
        $this->assertEquals(6, $line->fresh()->cost_price, 'the order line is left as ordered');
        $this->assertEquals(13, $product->selling_price);
        $this->assertEquals(8, $product->cost_price);
    }

    public function test_receiving_without_overrides_behaves_as_before(): void
    {
        $product = $this->product(['conversion_qty' => 1]);
        [$po, $line, $user] = $this->orderFor($product, 7);

        app(PurchaseReceivingService::class)->receive($po, [['line_item_id' => $line->id, 'qty' => 5, 'unit_cost' => '', 'selling_price' => '']], $user);

        $this->assertEquals(7, Batch::where('product_id', $product->id)->value('unit_cost'));
        $this->assertEquals(10, $product->fresh()->selling_price);
        $this->assertEquals(7, $product->fresh()->cost_price);
    }

    public function test_an_invalid_price_on_a_receipt_refuses_it_and_receives_nothing(): void
    {
        $product = $this->product(['conversion_qty' => 1]);   // cost 6
        [$po, $line, $user] = $this->orderFor($product, 6);

        try {
            // Price 5 with the cost syncing to 6 — cost would sit above price.
            app(PurchaseReceivingService::class)->receive($po, [['line_item_id' => $line->id, 'qty' => 5, 'selling_price' => '5']], $user);
            $this->fail('expected the receipt to be refused');
        } catch (RuntimeException $e) {
            $this->assertStringContainsString('selling price must be above', $e->getMessage());
        }

        $this->assertSame(0, Batch::where('product_id', $product->id)->count());
        $this->assertEquals(10, $product->fresh()->selling_price);
        $this->assertEquals(0, $line->fresh()->qty_received);
    }

    public function test_a_receipt_price_cannot_leave_a_promo_below_cost(): void
    {
        $product = $this->product(['conversion_qty' => 1, 'promo_discount_type' => 'percentage', 'promo_discount_value' => 50]);
        [$po, $line, $user] = $this->orderFor($product, 6);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('promotion');

        app(PurchaseReceivingService::class)->receive($po, [['line_item_id' => $line->id, 'qty' => 5, 'selling_price' => '11']], $user);   // 5.50 < 6
    }

    public function test_a_higher_cost_and_price_can_arrive_together(): void
    {
        $product = $this->product(['conversion_qty' => 1]);
        [$po, $line, $user] = $this->orderFor($product, 6);

        $warnings = app(PurchaseReceivingService::class)->receive($po, [['line_item_id' => $line->id, 'qty' => 5, 'unit_cost' => '11', 'selling_price' => '15']], $user);

        $this->assertSame([], $warnings);
        $this->assertEquals(15, $product->fresh()->selling_price);
        $this->assertEquals(11, $product->fresh()->cost_price);
    }

    public function test_the_receive_form_prefills_cost_and_price_and_passes_edits_through(): void
    {
        $product = $this->product(['conversion_qty' => 1]);
        [$po, $line, $user] = $this->orderFor($product, 6);

        Livewire::actingAs($user)->test(\App\Livewire\PurchaseOrders\PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->assertSet('receivingLines.0.unit_cost', '6')
            ->assertSet('receivingLines.0.selling_price', '10')
            ->set('receivingLines.0.qty', '5')
            ->set('receivingLines.0.unit_cost', '8')
            ->set('receivingLines.0.selling_price', '13')
            ->assertSee('This changes the selling price from 10.00 to 13.00')
            ->call('receive');

        $this->assertEquals(8, Batch::where('product_id', $product->id)->value('unit_cost'));
        $this->assertEquals(13, $product->fresh()->selling_price);
    }

    public function test_the_receive_form_rejects_a_non_numeric_price(): void
    {
        $product = $this->product(['conversion_qty' => 1]);
        [$po, $line, $user] = $this->orderFor($product, 6);

        Livewire::actingAs($user)->test(\App\Livewire\PurchaseOrders\PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->set('receivingLines.0.qty', '5')
            ->set('receivingLines.0.selling_price', 'abc')
            ->call('receive');

        $this->assertSame(0, Batch::where('product_id', $product->id)->count());
    }

    public function test_receiving_above_the_selling_price_records_the_real_cost_and_warns_instead_of_failing(): void
    {
        $product = $this->product(['conversion_qty' => 1]);   // price 10, cost 6
        [$po, $line, $user] = $this->orderFor($product, 15);

        $warnings = app(PurchaseReceivingService::class)->receive($po, [['line_item_id' => $line->id, 'qty' => 10]], $user);

        $this->assertCount(1, $warnings);
        $this->assertStringContainsString('not below its selling price', $warnings[0]);
        $this->assertEquals(15, Batch::where('product_id', $product->id)->value('unit_cost'), 'the batch keeps its real cost');
        $this->assertEquals(6, $product->fresh()->cost_price, 'the product reference cost is not pushed above the price');
    }

    public function test_receiving_at_a_sensible_cost_still_updates_the_reference_cost_with_no_warning(): void
    {
        $product = $this->product(['conversion_qty' => 1]);
        [$po, $line, $user] = $this->orderFor($product, 8);

        $warnings = app(PurchaseReceivingService::class)->receive($po, [['line_item_id' => $line->id, 'qty' => 10]], $user);

        $this->assertSame([], $warnings);
        $this->assertEquals(8, $product->fresh()->cost_price);
    }

    public function test_manual_stock_above_the_selling_price_is_recorded_and_flagged(): void
    {
        $product = $this->product();

        $batch = app(ManualStockService::class)->receive($product, 5, 'selling', 15, now()->toDateString(), null, null, null, User::factory()->create());

        $this->assertEquals(15, $batch->unit_cost);
        $this->assertEquals(6, $product->fresh()->cost_price);
        $this->assertNotNull($product->costAbovePriceWarning((float) $batch->unit_cost));
    }

    // -------------------------------------------------------- batch correction

    public function test_a_batch_cost_can_be_corrected_and_is_audited_with_the_reason(): void
    {
        $product = $this->product();
        $batch = Batch::factory()->for($product)->remaining(99)->create(['unit_cost' => 20]);

        app(BatchCostService::class)->correct($batch, 2.5, 'entered per pack, not per piece');

        $this->assertEquals(2.5, $batch->fresh()->unit_cost);

        $log = AuditLog::where('record_type', 'Batch')->where('record_id', $batch->id)->latest('id')->first();
        $this->assertEquals(20, $log->previous_value['unit_cost']);
        $this->assertEquals(2.5, $log->new_value['unit_cost']);
        $this->assertSame('entered per pack, not per piece', $log->new_value['reason']);
    }

    public function test_correcting_to_the_same_cost_is_refused(): void
    {
        $batch = Batch::factory()->for($this->product())->remaining(1)->create(['unit_cost' => 4]);

        $this->expectException(RuntimeException::class);

        app(BatchCostService::class)->correct($batch, 4.00, 'no-op');
    }

    public function test_the_correction_form_requires_a_reason_and_fixes_the_valuation(): void
    {
        $product = $this->product();
        $batch = Batch::factory()->for($product)->remaining(10)->create(['unit_cost' => 20]);
        $admin = User::factory()->create();

        $component = Livewire::actingAs($admin)->test(ProductProfile::class, ['product' => $product])
            ->call('openCostCorrection', $batch->id)
            ->assertSet('costCorrectionValue', '20.00')
            ->set('costCorrectionValue', '2')
            ->set('costCorrectionReason', '')
            ->call('submitCostCorrection')
            ->assertHasErrors(['costCorrectionReason']);

        $this->assertEquals(20, $batch->fresh()->unit_cost);

        $component->set('costCorrectionReason', 'typo')->call('submitCostCorrection')->assertHasNoErrors();

        $this->assertEquals(2, $batch->fresh()->unit_cost);
        // 10 units @ price 10 vs cost 2 -> profit 80, no longer a loss.
        $this->assertEquals(80.0, (float) DB::table('v_inventory_valuation')->where('product_id', $product->id)->value('estimated_gross_profit'));
    }

    public function test_a_batch_belonging_to_another_product_cannot_be_corrected_from_this_page(): void
    {
        $mine = $this->product();
        $other = Batch::factory()->for($this->product())->remaining(1)->create(['unit_cost' => 4]);

        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs(User::factory()->create())->test(ProductProfile::class, ['product' => $mine])
            ->call('openCostCorrection', $other->id);
    }
}
