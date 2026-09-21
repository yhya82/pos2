<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSessionNotExpired;
use App\Livewire\AuditLogs\AuditLogViewer;
use App\Livewire\Customers\CustomerProfile;
use App\Livewire\Dashboard\DashboardOverview;
use App\Livewire\Returns\ReturnManager;
use App\Livewire\Sales\SalesHistory;
use App\Models\Batch;
use App\Models\Customer;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Models\User;
use App\Services\ReturnService;
use App\Services\SaleService;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Livewire\Livewire;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Each role sees and uses only what it is allowed: a cashier only their own
 * sales (and the returns on them), only the administrator can refund anyone's
 * sale, and the audit log belongs to the Administrator role alone.
 */
class AccessScopeTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private User $ann;

    private User $ben;

    private Product $product;

    private Sale $annsSale;

    private Sale $bensSale;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
        $this->ann = User::factory()->cashier()->create(['name' => 'Ann Cashier']);
        $this->ben = User::factory()->cashier()->create(['name' => 'Ben Cashier']);

        $this->product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6]);
        Batch::factory()->for($this->product)->remaining(100)->create(['unit_cost' => 5]);

        $this->annsSale = $this->sell($this->ann);
        $this->bensSale = $this->sell($this->ben);
    }

    private function sell(User $cashier, ?int $customerId = null): Sale
    {
        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $this->product->id, 'quantity' => 2]],
            customerId: $customerId,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none',
            discountValue: 0,
            discountReason: null,
            cashier: $cashier,
        );
    }

    /** An authenticated browser request. Session expiry has its own tests; it isn't what's checked here. */
    private function browseAs(User $user): static
    {
        return $this->withoutMiddleware(EnsureSessionNotExpired::class)->actingAs($user);
    }

    private function refund(Sale $sale, User $by): SalesReturn
    {
        return app(ReturnService::class)->processReturn(
            $sale,
            [['sale_line_item_id' => $sale->lineItems()->first()->id, 'quantity' => 1, 'condition_type' => 'sellable']],
            'test',
            $by,
        );
    }

    // ------------------------------------------------------- seeing sales

    public function test_a_cashier_sees_only_their_own_sales(): void
    {
        Livewire::actingAs($this->ann)->test(SalesHistory::class)
            ->assertSee($this->annsSale->receipt_number)
            ->assertDontSee($this->bensSale->receipt_number);
    }

    public function test_the_administrator_sees_every_cashiers_sales(): void
    {
        Livewire::actingAs($this->admin)->test(SalesHistory::class)
            ->assertSee($this->annsSale->receipt_number)
            ->assertSee($this->bensSale->receipt_number);
    }

    public function test_a_cashier_can_open_only_their_own_receipts(): void
    {
        $this->browseAs($this->ann)->get(route('sales.receipt', $this->annsSale))->assertOk();
        $this->browseAs($this->ann)->get(route('sales.receipt', $this->bensSale))->assertForbidden();
        $this->browseAs($this->admin)->get(route('sales.receipt', $this->bensSale))->assertOk();
    }

    public function test_a_cashier_sees_only_their_own_sales_on_a_customers_profile(): void
    {
        $customer = Customer::factory()->create();
        $mine = $this->sell($this->ann, $customer->id);
        $theirs = $this->sell($this->ben, $customer->id);

        Livewire::actingAs($this->ann)->test(CustomerProfile::class, ['customer' => $customer])
            ->call('setTab', 'purchases')
            ->assertSee($mine->receipt_number)
            ->assertDontSee($theirs->receipt_number);
    }

    // ------------------------------------------------------------ refunds

    public function test_a_cashier_cannot_refund_someone_elses_sale_at_the_service_level(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('Only an administrator can refund a sale made by someone else');

        $this->refund($this->bensSale, $this->ann);
    }

    public function test_a_cashier_can_refund_their_own_sale(): void
    {
        $this->assertNotNull($this->refund($this->annsSale, $this->ann)->id);
    }

    public function test_the_administrator_can_refund_any_sale(): void
    {
        $this->assertNotNull($this->refund($this->annsSale, $this->admin)->id);
        $this->assertNotNull($this->refund($this->bensSale, $this->admin)->id);
    }

    public function test_the_refund_picker_lists_only_the_cashiers_own_sales_but_all_for_the_admin(): void
    {
        Livewire::actingAs($this->ann)->test(ReturnManager::class)
            ->call('startProcessing')
            ->assertSee($this->annsSale->receipt_number)
            ->assertDontSee($this->bensSale->receipt_number);

        Livewire::actingAs($this->admin)->test(ReturnManager::class)
            ->call('startProcessing')
            ->assertSee($this->annsSale->receipt_number)
            ->assertSee($this->bensSale->receipt_number);
    }

    public function test_a_cashier_cannot_pull_up_another_cashiers_sale_by_receipt_number(): void
    {
        Livewire::actingAs($this->ann)->test(ReturnManager::class)
            ->call('startProcessing')
            ->set('saleSearch', $this->bensSale->receipt_number)
            ->call('findSale')
            ->assertSet('foundSaleId', null)
            ->assertSee('No sale found with that receipt number');
    }

    public function test_a_cashier_cannot_select_another_cashiers_sale_by_id(): void
    {
        $this->expectException(ModelNotFoundException::class);

        Livewire::actingAs($this->ann)->test(ReturnManager::class)
            ->call('startProcessing')
            ->call('selectSale', $this->bensSale->id);
    }

    public function test_the_refund_deep_link_from_sales_history_works_for_own_sales_only(): void
    {
        Livewire::actingAs($this->ann)->test(ReturnManager::class, ['prefillReceipt' => $this->annsSale->receipt_number])
            ->assertNotSet('foundSaleId', null);

        Livewire::actingAs($this->ann)->test(ReturnManager::class, ['prefillReceipt' => $this->bensSale->receipt_number])
            ->assertSet('foundSaleId', null);
    }

    public function test_the_administrator_can_refund_another_cashiers_sale_through_the_screen(): void
    {
        Livewire::actingAs($this->admin)->test(ReturnManager::class)
            ->call('startProcessing')
            ->call('selectSale', $this->bensSale->id)
            ->set('returnLines.0.quantity', '1')
            ->set('overallReason', 'customer changed their mind')
            ->call('submitReturn')
            ->assertHasNoErrors();

        $this->assertSame(1, SalesReturn::where('original_sale_id', $this->bensSale->id)->count());
    }

    public function test_someone_who_can_refund_but_isnt_admin_or_the_seller_gets_no_refund_link(): void
    {
        $role = Role::create(['name' => 'Floor Manager', 'description' => 'sees all sales, refunds only own', 'status' => 'active']);
        $role->permissions()->attach(Permission::where(fn ($q) => $q
            ->where(fn ($p) => $p->where('module', 'sales')->where('action', 'view'))
            ->orWhere(fn ($p) => $p->where('module', 'returns')->whereIn('action', ['view', 'create']))
        )->pluck('id'));
        $manager = User::factory()->create(['role_id' => $role->id]);
        $managersSale = $this->sell($manager);

        Livewire::actingAs($manager)->test(SalesHistory::class)
            ->assertSee($this->annsSale->receipt_number)                          // can browse everything...
            ->assertSee(route('returns.index', ['receipt' => $managersSale->receipt_number]), false)
            ->assertDontSee(route('returns.index', ['receipt' => $this->annsSale->receipt_number]), false);   // ...but only refund their own
    }

    // ------------------------------------------------------------ returns

    public function test_a_cashier_sees_only_returns_on_their_own_sales(): void
    {
        $mine = $this->refund($this->annsSale, $this->admin);
        $theirs = $this->refund($this->bensSale, $this->admin);

        Livewire::actingAs($this->ann)->test(ReturnManager::class)
            ->assertSee($mine->return_number)
            ->assertDontSee($theirs->return_number);

        Livewire::actingAs($this->admin)->test(ReturnManager::class)
            ->assertSee($mine->return_number)
            ->assertSee($theirs->return_number);
    }

    public function test_a_cashier_can_open_only_return_receipts_for_their_own_sales(): void
    {
        $mine = $this->refund($this->annsSale, $this->admin);
        $theirs = $this->refund($this->bensSale, $this->admin);

        $this->browseAs($this->ann)->get(route('returns.receipt', $mine))->assertOk();
        $this->browseAs($this->ann)->get(route('returns.receipt', $theirs))->assertForbidden();
        $this->browseAs($this->admin)->get(route('returns.receipt', $theirs))->assertOk();
    }

    public function test_the_dashboard_refund_total_counts_only_the_cashiers_own_refunds(): void
    {
        $this->refund($this->annsSale, $this->admin);      // 1 x 10.00
        $this->refund($this->bensSale, $this->admin);
        $this->refund($this->bensSale, $this->admin);      // Ben: 20.00 more

        $annsView = Livewire::actingAs($this->ann)->test(DashboardOverview::class)->viewData('refundsTotal');
        $adminsView = Livewire::actingAs($this->admin)->test(DashboardOverview::class)->viewData('refundsTotal');

        $this->assertEquals(10.00, $annsView);
        $this->assertEquals(30.00, $adminsView);
    }

    // ---------------------------------------------------------- audit log

    public function test_only_the_administrator_role_can_open_the_audit_log(): void
    {
        $this->browseAs($this->admin)->get(route('audit-logs.index'))->assertOk();
        $this->browseAs($this->ann)->get(route('audit-logs.index'))->assertForbidden();
    }

    public function test_granting_the_audit_permission_to_another_role_does_not_open_it(): void
    {
        $role = Role::create(['name' => 'Auditor', 'description' => 'given the permission anyway', 'status' => 'active']);
        $role->permissions()->attach(Permission::where('module', 'audit_logs')->where('action', 'view')->pluck('id'));
        $auditor = User::factory()->create(['role_id' => $role->id]);

        $this->assertTrue($auditor->hasPermission('audit_logs', 'view'));

        $this->browseAs($auditor)->get(route('audit-logs.index'))->assertForbidden();

        Livewire::actingAs($auditor)->test(AuditLogViewer::class)->assertForbidden();
    }

    public function test_the_audit_log_link_is_only_in_the_administrators_sidebar(): void
    {
        $role = Role::create(['name' => 'Auditor', 'description' => 'given the permission anyway', 'status' => 'active']);
        $role->permissions()->attach(Permission::where('module', 'audit_logs')->where('action', 'view')->pluck('id'));
        $auditor = User::factory()->create(['role_id' => $role->id]);

        Livewire::actingAs($this->admin)->test(\App\Livewire\Layout\Sidebar::class)->assertSee('Audit Logs');
        Livewire::actingAs($auditor)->test(\App\Livewire\Layout\Sidebar::class)->assertDontSee('Audit Logs');
    }

    public function test_a_return_without_a_reason_is_refused_with_a_plain_message(): void
    {
        Livewire::actingAs($this->admin)->test(ReturnManager::class)
            ->call('startProcessing')
            ->call('selectSale', $this->annsSale->id)
            ->set('returnLines.0.quantity', '1')
            ->call('submitReturn')
            ->assertHasErrors('overallReason');

        $this->assertSame(0, SalesReturn::count());
    }
}
