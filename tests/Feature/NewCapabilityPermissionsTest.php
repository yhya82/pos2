<?php

namespace Tests\Feature;

use App\Livewire\PurchaseOrders\PurchaseOrderManager;
use App\Livewire\Roles\RoleManager;
use App\Models\Batch;
use App\Models\Permission;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\ReceivingIssue;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\PurchaseReceivingService;
use App\Services\SaleService;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * "Seeing financials", "refunding any sale" and "closing a line short /
 * settling a supplier claim" used to be decided by a role's literal name
 * (Cashier / Administrator). They're now ordinary permissions — grantable
 * to any role from the Roles screen, not just the two built-in ones.
 */
class NewCapabilityPermissionsTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private function userWith(array $grants): User
    {
        $role = Role::create(['name' => 'Custom '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $role->permissions()->attach(collect($grants)->map(
            fn ($g) => Permission::where('module', $g[0])->where('action', $g[1])->value('id')
        ));

        return User::factory()->create(['role_id' => $role->id]);
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    // -------------------------------------------------------- the catalog

    public function test_the_three_permissions_exist_in_the_catalog(): void
    {
        $this->assertDatabaseHas('permissions', ['module' => 'financials', 'action' => 'view']);
        $this->assertDatabaseHas('permissions', ['module' => 'supplier_claims', 'action' => 'update']);
        $this->assertDatabaseHas('permissions', ['module' => 'returns', 'action' => 'update']);
    }

    public function test_they_appear_as_checkboxes_on_the_roles_screen(): void
    {
        Livewire::actingAs($this->admin)->test(RoleManager::class)
            ->call('create')
            ->assertSee('Financials')
            ->assertSee('Supplier Claims')
            ->assertSeeHtml('value="'.Permission::where('module', 'financials')->where('action', 'view')->value('id').'"')
            ->assertSeeHtml('value="'.Permission::where('module', 'supplier_claims')->where('action', 'update')->value('id').'"');
    }

    public function test_the_default_grants_match_the_old_hardcoded_behaviour(): void
    {
        // Only Administrator and Cashier exist from the base schema seed — any
        // other role (like a real "Inventory Clerk" made through the Roles
        // screen) is created after these migrations run, so the migration's
        // "every non-Cashier role" rule has nothing more to apply to here.
        $admin = Role::where('name', 'Administrator')->firstOrFail();
        $cashier = Role::where('name', 'Cashier')->firstOrFail();

        $this->assertTrue($admin->permissions->contains(fn ($p) => $p->module === 'financials' && $p->action === 'view'));
        $this->assertFalse($cashier->permissions->contains(fn ($p) => $p->module === 'financials' && $p->action === 'view'));

        $this->assertTrue($admin->permissions->contains(fn ($p) => $p->module === 'returns' && $p->action === 'update'));
        $this->assertTrue($admin->permissions->contains(fn ($p) => $p->module === 'supplier_claims' && $p->action === 'update'));
        $this->assertFalse($cashier->permissions->contains(fn ($p) => $p->module === 'returns' && $p->action === 'update'));
        $this->assertFalse($cashier->permissions->contains(fn ($p) => $p->module === 'supplier_claims' && $p->action === 'update'));
    }

    public function test_a_role_created_after_the_migration_gets_none_of_the_three_automatically(): void
    {
        // The "grant to every non-Cashier role" rule only ran once, against the
        // roles that existed at migration time — a brand new role starts with
        // none of them, same as any other permission.
        $freshRole = Role::create(['name' => 'Brand New Role', 'description' => 'test', 'status' => 'active']);

        $this->assertFalse($freshRole->permissions->contains(fn ($p) => $p->module === 'financials'));
        $this->assertFalse($freshRole->permissions->contains(fn ($p) => $p->module === 'supplier_claims'));
    }

    // ---------------------------------------------------------- financials

    public function test_financials_view_can_be_granted_to_any_role_without_it_being_named_anything_special(): void
    {
        $withoutIt = $this->userWith([['products', 'view']]);
        $withIt = $this->userWith([['products', 'view'], ['financials', 'view']]);

        $this->assertFalse($withoutIt->canSeeFinancials());
        $this->assertTrue($withIt->canSeeFinancials());
    }

    public function test_a_cashier_granted_the_permission_directly_now_sees_financials(): void
    {
        // Renaming or otherwise identifying as "Cashier" no longer matters —
        // only the permission does.
        $cashier = User::factory()->cashier()->create();
        $cashier->role->permissions()->attach(Permission::where('module', 'financials')->where('action', 'view')->value('id'));

        $this->assertTrue(User::find($cashier->id)->canSeeFinancials());
    }

    // ------------------------------------------------------------ refunds

    private function completedSale(User $cashier): Sale
    {
        $product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6]);
        Batch::factory()->for($product)->remaining(20)->create(['unit_cost' => 5]);

        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 1]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none', discountValue: 0, discountReason: null,
            cashier: $cashier,
        );
    }

    public function test_a_non_administrator_with_returns_update_can_refund_someone_elses_sale(): void
    {
        $otherCashier = User::factory()->cashier()->create();
        $sale = $this->completedSale($otherCashier);

        $withoutIt = $this->userWith([['returns', 'create']]);
        $withIt = $this->userWith([['returns', 'create'], ['returns', 'update']]);

        $this->assertFalse($withoutIt->canRefundSale($sale));
        $this->assertTrue($withIt->canRefundSale($sale));
    }

    public function test_returns_update_widens_which_sales_are_eligible_but_returns_create_is_still_what_lets_someone_process_one(): void
    {
        // Someone with returns.update but no returns.create can name any sale
        // as eligible, but is still refused when actually submitting a return —
        // the base "may process a return at all" gate is a separate check.
        $sale = $this->completedSale(User::factory()->cashier()->create());
        $manager = $this->userWith([['returns', 'update'], ['sales', 'view']]);

        $this->assertTrue($manager->canRefundSale($sale));

        Livewire::actingAs($manager)->test(\App\Livewire\Returns\ReturnManager::class)
            ->call('startProcessing')
            ->assertForbidden();
    }

    public function test_the_refund_picker_lists_every_sale_for_someone_with_returns_update(): void
    {
        $mine = $this->completedSale(User::factory()->cashier()->create());
        $theirs = $this->completedSale(User::factory()->cashier()->create());

        $manager = $this->userWith([['returns', 'view'], ['returns', 'create'], ['returns', 'update']]);

        Livewire::actingAs($manager)->test(\App\Livewire\Returns\ReturnManager::class)
            ->call('startProcessing')
            ->assertSee($mine->receipt_number)
            ->assertSee($theirs->receipt_number);
    }

    // ------------------------------------------------------- supplier claims

    private function claimFor(User $creator): array
    {
        $product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6, 'conversion_qty' => 1]);
        $unit = Unit::factory()->create();
        $product->update(['purchase_unit_id' => $unit->id, 'selling_unit_id' => $unit->id]);

        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => Supplier::create(['name' => 'Claim Sup', 'status' => 'active'])->id,
            'status' => 'ordered', 'order_date' => now()->toDateString(), 'created_by' => $creator->id,
        ]);
        $line = $po->lineItems()->create(['product_id' => $product->id, 'qty_ordered' => 10, 'purchase_unit_id' => $unit->id, 'cost_price' => 6]);

        app(PurchaseReceivingService::class)->receive($po, [[
            'line_item_id' => $line->id, 'qty' => 5, 'damaged_qty' => 2, 'damaged_reason' => 'crushed',
        ]], $creator);

        return [$po, ReceivingIssue::firstOrFail()];
    }

    public function test_a_non_administrator_with_supplier_claims_update_can_close_short_and_settle_a_claim(): void
    {
        [$po, $issue] = $this->claimFor($this->admin);

        $clerk = $this->userWith([['purchase_orders', 'view'], ['purchase_orders', 'update'], ['supplier_claims', 'update']]);

        Livewire::actingAs($clerk)->test(PurchaseOrderManager::class)
            ->call('view', $po->id)
            ->assertSee('Settle')                            // the settle-claim action is offered
            ->call('openResolve', $issue->id)
            ->assertHasNoErrors()
            ->set('resolveAmount', '5')
            ->call('submitResolve')
            ->assertHasNoErrors();

        $this->assertSame('credited', $issue->fresh()->claim_status);
    }

    public function test_without_the_permission_the_write_off_actions_stay_refused(): void
    {
        [, $issue] = $this->claimFor($this->admin);

        $clerk = $this->userWith([['purchase_orders', 'view'], ['purchase_orders', 'update']]);

        Livewire::actingAs($clerk)->test(PurchaseOrderManager::class)
            ->call('openResolve', $issue->id)
            ->assertForbidden();

        $this->assertSame('owed', $issue->fresh()->claim_status);
    }

    public function test_products_update_alone_no_longer_grants_write_off_rights(): void
    {
        // Before this change, holding products.update was enough. Now it isn't —
        // the two are independent permissions.
        [, $issue] = $this->claimFor($this->admin);

        $editor = $this->userWith([['purchase_orders', 'view'], ['purchase_orders', 'update'], ['products', 'update']]);

        Livewire::actingAs($editor)->test(PurchaseOrderManager::class)
            ->call('openResolve', $issue->id)
            ->assertForbidden();
    }
}
