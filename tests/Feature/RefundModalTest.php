<?php

namespace Tests\Feature;

use App\Livewire\Sales\RefundModal;
use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\User;
use App\Services\SaleService;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The Sales page's own Refund action: opens as a modal instead of
 * navigating to the Returns page, but drives the exact same
 * ReturnService the Returns page's own "process a known sale" flow
 * always has — no second definition of "what's left to return" or how
 * a return is actually processed.
 */
class RefundModalTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private Product $product;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->product = Product::factory()->create(['selling_price' => 10]);
        Batch::factory()->for($this->product)->remaining(100)->create();
    }

    private function sell(User $cashier, int $quantity = 2): Sale
    {
        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $this->product->id, 'quantity' => $quantity]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $cashier,
        );
    }

    public function test_opening_loads_the_sales_real_returnable_lines(): void
    {
        $sale = $this->sell($this->admin, 3);

        Livewire::actingAs($this->admin)->test(RefundModal::class)
            ->call('open', $sale->id)
            ->assertSet('saleId', $sale->id)
            ->assertSet('returnLines.0.product_name', $this->product->name)
            ->assertSet('returnLines.0.max_returnable', 3.0)
            ->assertDispatched('open-modal', 'refund-sale');
    }

    public function test_opening_requires_the_returns_create_permission(): void
    {
        $sale = $this->sell($this->admin);
        $role = Role::create(['name' => 'No Returns '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $user = User::factory()->create(['role_id' => $role->id]);

        Livewire::actingAs($user)->test(RefundModal::class)
            ->call('open', $sale->id)
            ->assertForbidden();
    }

    public function test_a_cashier_cannot_open_the_refund_modal_for_someone_elses_sale(): void
    {
        $other = User::factory()->cashier()->create();
        $sale = $this->sell($other);

        $role = Role::create(['name' => 'Cashier-ish '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $role->permissions()->attach(Permission::where('module', 'returns')->where('action', 'create')->value('id'));
        $cashier = User::factory()->create(['role_id' => $role->id]);

        Livewire::actingAs($cashier)->test(RefundModal::class)
            ->call('open', $sale->id)
            ->assertForbidden();
    }

    public function test_a_sale_that_is_no_longer_completed_shows_an_error_not_the_form(): void
    {
        // Fully returning a sale's one line flips the sale itself to
        // "refunded" via the schema's own status-sync trigger — same
        // real behavior ReturnManager's own sale-loading already relies
        // on, so trying to refund it again is refused up front.
        $sale = $this->sell($this->admin, 1);
        app(\App\Services\ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 1, 'condition_type' => 'sellable']],
            'fully returned already',
            $this->admin,
        );

        Livewire::actingAs($this->admin)->test(RefundModal::class)
            ->call('open', $sale->id)
            ->assertSet('saleId', null)
            ->assertSee('only completed sales can have a return processed');
    }

    public function test_submitting_processes_the_real_return_and_closes_the_modal(): void
    {
        $sale = $this->sell($this->admin, 2);

        Livewire::actingAs($this->admin)->test(RefundModal::class)
            ->call('open', $sale->id)
            ->set('returnLines.0.quantity', '1')
            ->set('overallReason', 'customer changed their mind')
            ->call('submit')
            ->assertDispatched('close-modal', 'refund-sale')
            ->assertDispatched('flash-message')
            ->assertSet('saleId', null);

        $this->assertDatabaseHas('sales_returns', ['original_sale_id' => $sale->id]);
    }

    public function test_submitting_without_a_reason_is_refused_with_a_plain_message(): void
    {
        $sale = $this->sell($this->admin, 2);

        Livewire::actingAs($this->admin)->test(RefundModal::class)
            ->call('open', $sale->id)
            ->set('returnLines.0.quantity', '1')
            ->call('submit')
            ->assertHasErrors('overallReason');
    }

    public function test_the_modal_is_rendered_once_on_the_sales_page_not_per_row(): void
    {
        $this->sell($this->admin);
        $this->sell($this->admin);

        $html = Livewire::actingAs($this->admin)->test(\App\Livewire\Sales\SalesHistory::class)->html();

        // The dialog's own heading is only ever in the one rendered
        // livewire:sales.refund-modal instance, not per table row.
        $this->assertSame(1, substr_count($html, 'Refund sale'));
    }
}
