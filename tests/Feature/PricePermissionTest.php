<?php

namespace Tests\Feature;

use App\Livewire\Products\ProductProfile;
use App\Livewire\PurchaseOrders\PurchaseOrderManager;
use App\Models\Batch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Costs and selling prices can only be set by hand by someone who may edit
 * products. A stock clerk who can order and receive sees them read-only, and
 * anything they submit for them is ignored — the read-only input is only the
 * politeness, the server is the protection.
 */
class PricePermissionTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private User $clerk;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();

        $role = Role::create(['name' => 'Stock Clerk', 'description' => 'orders and receives, no price rights', 'status' => 'active']);
        $ids = Permission::where(fn ($q) => $q
            ->where(fn ($p) => $p->where('module', 'purchase_orders')->whereIn('action', ['view', 'create', 'update']))
            ->orWhere(fn ($p) => $p->where('module', 'inventory')->whereIn('action', ['view', 'update']))
            ->orWhere(fn ($p) => $p->where('module', 'products')->where('action', 'view'))
        )->pluck('id');
        $role->permissions()->attach($ids);
        $this->clerk = User::factory()->create(['role_id' => $role->id]);

        // Price 10, cost 6, same unit both ways so cost per unit is simply 6.
        $unit = Unit::factory()->create();
        $this->product = Product::factory()->create([
            'selling_price' => 10, 'cost_price' => 6, 'conversion_qty' => 1,
            'purchase_unit_id' => $unit->id, 'selling_unit_id' => $unit->id,
        ]);
    }

    private function supplier(): Supplier
    {
        return Supplier::firstOrCreate(['name' => 'Perm Sup'], ['status' => 'active']);
    }

    private function orderFor(User $creator, float $cost = 6): PurchaseOrder
    {
        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => $this->supplier()->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'created_by' => $creator->id,
        ]);
        $po->lineItems()->create([
            'product_id' => $this->product->id, 'qty_ordered' => 10,
            'purchase_unit_id' => $this->product->purchase_unit_id, 'cost_price' => $cost,
        ]);

        return $po;
    }

    // ------------------------------------------------- PO create / edit form

    public function test_a_clerk_cannot_set_a_cost_on_a_new_order_whatever_they_submit(): void
    {
        Livewire::actingAs($this->clerk)->test(PurchaseOrderManager::class)
            ->call('create')
            ->set('supplierId', $this->supplier()->id)
            ->set('lines.0.product_id', $this->product->id)
            ->set('lines.0.qty_ordered', '10')
            ->set('lines.0.cost_price', '1')                       // tampered
            ->set('lines.0.purchase_unit_id', Unit::factory()->create()->id)   // tampered
            ->call('save')
            ->assertHasNoErrors();

        $line = PurchaseOrder::latest('id')->first()->lineItems()->first();
        $this->assertEquals(6, $line->cost_price, 'the product\'s own cost, not 1');
        $this->assertEquals($this->product->purchase_unit_id, $line->purchase_unit_id);
    }

    public function test_an_admin_can_still_set_the_cost_on_an_order(): void
    {
        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('create')
            ->set('supplierId', $this->supplier()->id)
            ->set('lines.0.product_id', $this->product->id)
            ->set('lines.0.qty_ordered', '10')
            ->set('lines.0.cost_price', '8')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertEquals(8, PurchaseOrder::latest('id')->first()->lineItems()->first()->cost_price);
    }

    public function test_a_clerk_editing_a_draft_keeps_the_cost_an_admin_set(): void
    {
        $po = $this->orderFor($this->admin, 8);
        $po->update(['status' => 'draft']);

        Livewire::actingAs($this->clerk)->test(PurchaseOrderManager::class)
            ->call('edit', $po->id)
            ->set('lines.0.qty_ordered', '20')
            ->set('lines.0.cost_price', '1')                       // tampered
            ->call('save')
            ->assertHasNoErrors();

        $line = $po->fresh()->lineItems()->first();
        $this->assertEquals(20, $line->qty_ordered);
        $this->assertEquals(8, $line->cost_price);
    }

    public function test_the_form_shows_a_clerk_the_cost_read_only_and_an_admin_an_input(): void
    {
        Livewire::actingAs($this->clerk)->test(PurchaseOrderManager::class)
            ->call('create')
            ->assertSee('Costs come from the product')
            ->assertDontSeeHtml('wire:model.live.debounce.300ms="lines.0.cost_price"');

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('create')
            ->assertDontSee('Costs come from the product')
            ->assertSeeHtml('wire:model.live.debounce.300ms="lines.0.cost_price"');
    }

    // ------------------------------------------------------------- PO totals

    public function test_the_order_form_shows_line_totals_and_an_order_total(): void
    {
        $other = Product::factory()->create(['selling_price' => 20, 'cost_price' => 9, 'conversion_qty' => 1]);

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('create')
            ->call('addLine')
            ->set('lines.0.product_id', $this->product->id)
            ->set('lines.0.qty_ordered', '10')
            ->set('lines.0.cost_price', '6')                       // 60.00
            ->set('lines.1.product_id', $other->id)
            ->set('lines.1.qty_ordered', '5')
            ->set('lines.1.cost_price', '9')                       // 45.00
            ->assertSee('Line total: 60.00')
            ->assertSee('Line total: 45.00')
            ->assertSee('Order total')
            ->assertSee('105.00');
    }

    // ------------------------------------------------------------- receiving

    public function test_a_clerk_receiving_cannot_change_the_cost_or_the_price(): void
    {
        $po = $this->orderFor($this->admin, 6);

        Livewire::actingAs($this->clerk)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->set('receivingLines.0.qty', '5')
            ->set('receivingLines.0.unit_cost', '1')               // tampered
            ->set('receivingLines.0.selling_price', '99')          // tampered
            ->call('receive');

        $this->assertEquals(6, Batch::where('product_id', $this->product->id)->value('unit_cost'));
        $this->assertEquals(10, $this->product->fresh()->selling_price);
    }

    public function test_an_admin_receiving_can_still_change_both(): void
    {
        $po = $this->orderFor($this->admin, 6);

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->set('receivingLines.0.qty', '5')
            ->set('receivingLines.0.unit_cost', '8')
            ->set('receivingLines.0.selling_price', '13')
            ->call('receive');

        $this->assertEquals(8, Batch::where('product_id', $this->product->id)->value('unit_cost'));
        $this->assertEquals(13, $this->product->fresh()->selling_price);
    }

    // ------------------------------------------------------- product page

    public function test_a_clerk_adding_stock_gets_the_products_own_cost_and_price(): void
    {
        Livewire::actingAs($this->clerk)->test(ProductProfile::class, ['product' => $this->product])
            ->call('openAddStockForm')
            ->set('addStockQty', '4')
            ->set('addStockUnitCost', '1')                         // tampered
            ->set('addStockSellingPrice', '99')                    // tampered
            ->call('submitAddStock')
            ->assertHasNoErrors();

        $this->assertEquals(6, Batch::where('product_id', $this->product->id)->value('unit_cost'));
        $this->assertEquals(10, $this->product->fresh()->selling_price);
    }

    public function test_a_clerk_cannot_correct_a_batch_cost(): void
    {
        $batch = Batch::factory()->for($this->product)->remaining(10)->create(['unit_cost' => 5]);

        Livewire::actingAs($this->clerk)->test(ProductProfile::class, ['product' => $this->product])
            ->call('openCostCorrection', $batch->id)
            ->assertForbidden();

        $this->assertEquals(5, $batch->fresh()->unit_cost);
    }

    public function test_the_correct_cost_button_is_hidden_from_a_clerk(): void
    {
        Batch::factory()->for($this->product)->remaining(10)->create(['unit_cost' => 5]);

        Livewire::actingAs($this->clerk)->test(ProductProfile::class, ['product' => $this->product])
            ->call('setTab', 'inventory')
            ->assertDontSee('Correct cost');

        Livewire::actingAs($this->admin)->test(ProductProfile::class, ['product' => $this->product])
            ->call('setTab', 'inventory')
            ->assertSee('Correct cost');
    }
}
