<?php

namespace Tests\Feature;

use App\Livewire\PurchaseOrders\PurchaseOrderManager;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class PurchaseOrderItemsListTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private function order(int $products, string $status = 'ordered'): PurchaseOrder
    {
        $user = User::factory()->create();
        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => Supplier::create(['name' => 'List Sup', 'status' => 'active'])->id,
            'status' => $status,
            'order_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);

        foreach (range(1, $products) as $i) {
            $product = Product::factory()->create(['name' => "Item Number {$i}"]);
            $po->lineItems()->create([
                'product_id' => $product->id,
                'qty_ordered' => 10,
                'purchase_unit_id' => $product->purchase_unit_id,
                'cost_price' => 5,
            ]);
        }

        return $po;
    }

    public function test_the_list_shows_the_product_names_and_quantities_ordered(): void
    {
        $this->order(2);

        Livewire::actingAs(User::factory()->create())->test(PurchaseOrderManager::class)
            ->assertSee('Item Number 1')
            ->assertSee('Item Number 2')
            ->assertSee('× 10');
    }

    public function test_a_long_order_shows_the_first_three_and_a_count_of_the_rest(): void
    {
        $this->order(5);

        Livewire::actingAs(User::factory()->create())->test(PurchaseOrderManager::class)
            ->assertSee('Item Number 3')
            ->assertSee('+ 2 more');
    }

    public function test_received_quantities_show_once_stock_has_come_in(): void
    {
        $po = $this->order(1);
        $po->lineItems()->first()->update(['qty_received' => 4]);

        Livewire::actingAs(User::factory()->create())->test(PurchaseOrderManager::class)
            ->assertSee('4 received');
    }

    public function test_the_details_panel_lists_every_product_however_long_the_order(): void
    {
        $po = $this->order(12);

        $component = Livewire::actingAs(User::factory()->create())->test(PurchaseOrderManager::class)
            ->call('view', $po->id)
            ->assertSee('Items ordered (12)')
            ->assertSee('Order total');

        foreach (range(1, 12) as $i) {
            $component->assertSee("Item Number {$i}");
        }
    }

    public function test_the_details_panel_totals_the_order_and_shows_what_is_left(): void
    {
        $po = $this->order(2, 'ordered');   // 2 lines x 10 @ 5.00 = 100.00
        $po->lineItems()->first()->update(['qty_received' => 4]);

        Livewire::actingAs(User::factory()->create())->test(PurchaseOrderManager::class)
            ->call('view', $po->id)
            ->assertSee('100.00')
            ->assertSee('Nothing has been received against this order yet.');
    }

    public function test_the_details_panel_lists_deliveries_with_the_cost_actually_paid(): void
    {
        $po = $this->order(1);
        $line = $po->lineItems()->first();
        $user = User::factory()->create();

        app(\App\Services\PurchaseReceivingService::class)->receive($po, [[
            'line_item_id' => $line->id, 'qty' => 4, 'unit_cost' => '7.5', 'batch_code' => 'LOT-77',
        ]], $user);

        Livewire::actingAs($user)->test(PurchaseOrderManager::class)
            ->call('view', $po->id)
            ->assertSee('Deliveries received')
            ->assertSee('LOT-77')
            ->assertSee('Cost paid')
            ->assertDontSee('Nothing has been received');
    }

    private function packProduct(): Product
    {
        return Product::factory()->create([
            'name' => 'Pack Item',
            'conversion_qty' => 12,
            'purchase_unit_id' => \App\Models\Unit::factory()->create(['name' => 'carton'])->id,
            'selling_unit_id' => \App\Models\Unit::factory()->create(['name' => 'bottle'])->id,
        ]);
    }

    public function test_the_create_form_shows_the_read_only_conversion_and_a_live_total(): void
    {
        $product = $this->packProduct();

        Livewire::actingAs(User::factory()->create())->test(PurchaseOrderManager::class)
            ->call('create')
            ->call('addLine')
            ->set('lines.0.product_id', $product->id)
            ->assertSee('1 carton = 12 bottle')
            ->set('lines.0.qty_ordered', '10')
            ->assertSee('= 120 bottle');
    }

    public function test_the_create_form_shows_no_conversion_when_both_units_are_the_same(): void
    {
        $unit = \App\Models\Unit::factory()->create(['name' => 'sameunit']);
        $product = Product::factory()->create(['purchase_unit_id' => $unit->id, 'selling_unit_id' => $unit->id, 'conversion_qty' => 1]);

        Livewire::actingAs(User::factory()->create())->test(PurchaseOrderManager::class)
            ->call('create')
            ->call('addLine')
            ->set('lines.0.product_id', $product->id)
            ->set('lines.0.qty_ordered', '10')
            ->assertDontSee('1 sameunit =')
            ->assertDontSee('= 10 sameunit');
    }

    public function test_the_receive_panel_shows_the_conversion_and_what_will_be_added_to_stock(): void
    {
        $product = $this->packProduct();
        $user = User::factory()->create();
        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => Supplier::create(['name' => 'Conv Sup', 'status' => 'active'])->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
        $po->lineItems()->create(['product_id' => $product->id, 'qty_ordered' => 10, 'purchase_unit_id' => $product->purchase_unit_id, 'cost_price' => 60]);

        Livewire::actingAs($user)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->assertSee('1 carton = 12 bottle')
            ->assertDontSee('Adds')
            ->set('receivingLines.0.qty', '3')
            ->assertSee('Adds 36 bottle to stock');
    }
}
