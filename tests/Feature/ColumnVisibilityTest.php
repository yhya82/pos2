<?php

namespace Tests\Feature;

use App\Livewire\Categories\CategoryManager;
use App\Livewire\Customers\CustomerManager;
use App\Livewire\Dashboard\DashboardOverview;
use App\Livewire\Products\ProductManager;
use App\Livewire\Products\ProductProfile;
use App\Livewire\Roles\RoleManager;
use App\Livewire\Suppliers\SupplierManager;
use App\Livewire\Units\UnitManager;
use App\Livewire\Users\UserManager;
use App\Models\Batch;
use App\Models\Permission;
use App\Models\Product;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * If a user can't use anything in a column, the column isn't shown — header
 * and cells — instead of a blank one. And costs/profit are hidden from roles
 * that don't see the shop's finances.
 */
class ColumnVisibilityTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private function userWith(array $grants): User
    {
        $role = Role::create(['name' => 'Custom '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $ids = collect($grants)->map(fn ($g) => Permission::where('module', $g[0])->where('action', $g[1])->value('id'));
        $role->permissions()->attach($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    /** @return array<string, array{0: class-string, 1: string}> */
    public static function tables(): array
    {
        return [
            'categories' => [CategoryManager::class, 'categories'],
            'customers' => [CustomerManager::class, 'customers'],
            'products' => [ProductManager::class, 'products'],
            'suppliers' => [SupplierManager::class, 'suppliers'],
            'units' => [UnitManager::class, 'products'],
            'users' => [UserManager::class, 'users'],
            'roles' => [RoleManager::class, 'roles'],
        ];
    }

    #[DataProvider('tables')]
    public function test_a_view_only_user_sees_no_actions_column(string $component, string $module): void
    {
        $viewer = $this->userWith([[$module, 'view']]);

        Livewire::actingAs($viewer)->test($component)
            ->assertDontSeeHtml('>Actions</th>')
            ->assertDontSee('Deactivate');
    }

    #[DataProvider('tables')]
    public function test_someone_who_can_edit_sees_the_column(string $component, string $module): void
    {
        $editor = $this->userWith([[$module, 'view'], [$module, 'update']]);

        Livewire::actingAs($editor)->test($component)
            ->assertSeeHtml('>Actions</th>');
    }

    #[DataProvider('tables')]
    public function test_someone_who_can_only_deactivate_still_gets_the_column(string $component, string $module): void
    {
        $remover = $this->userWith([[$module, 'view'], [$module, 'delete']]);

        Livewire::actingAs($remover)->test($component)
            ->assertSeeHtml('>Actions</th>');
    }

    #[DataProvider('tables')]
    public function test_the_administrator_always_has_the_column(string $component, string $module): void
    {
        Livewire::actingAs(User::factory()->create())->test($component)
            ->assertSeeHtml('>Actions</th>');
    }

    public function test_the_empty_message_still_spans_the_table_when_the_column_is_hidden(): void
    {
        // Suppliers: 6 columns with Actions, 5 without. No rows, so the empty row shows.
        $viewer = $this->userWith([['suppliers', 'view']]);

        Livewire::actingAs($viewer)->test(SupplierManager::class)
            ->assertSeeHtml('colspan="5"');

        Livewire::actingAs(User::factory()->create())->test(SupplierManager::class)
            ->assertSeeHtml('colspan="6"');
    }

    // ---------------------------------------------------- costs and profit

    private function productWithBatch(): Product
    {
        $product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6]);
        Batch::factory()->for($product)->remaining(20)->create(['unit_cost' => 5]);

        return $product;
    }

    public function test_who_counts_as_seeing_the_finances(): void
    {
        // A real permission (financials.view), not a role-name check — granted
        // to the Administrator role by the migration that introduced it.
        $this->assertTrue(User::factory()->create()->canSeeFinancials());                                       // administrator
        $this->assertFalse($this->userWith([['products', 'view']])->canSeeFinancials());                        // a custom role without it
        $this->assertTrue($this->userWith([['products', 'view'], ['financials', 'view']])->canSeeFinancials());  // ...and with it
        $this->assertFalse(User::factory()->cashier()->create()->canSeeFinancials());                            // a cashier
    }

    private function cashierWhoCanBrowseProducts(): User
    {
        $cashier = User::factory()->cashier()->create();
        $cashier->role->permissions()->syncWithoutDetaching(Permission::whereIn('module', ['products', 'inventory'])->where('action', 'view')->pluck('id'));

        return User::find($cashier->id);
    }

    public function test_a_cashier_never_sees_costs_or_profit_on_a_product(): void
    {
        $product = $this->productWithBatch();

        $component = Livewire::actingAs($this->cashierWhoCanBrowseProducts())->test(ProductProfile::class, ['product' => $product])
            ->assertDontSee('6.00 per')
            ->assertDontSee('≈ Cost per');

        $component->call('setTab', 'inventory')
            ->assertSee('Remaining')
            ->assertDontSee('Unit Cost')
            ->assertDontSee('Est. Profit')
            ->assertDontSee('Profit (per')
            ->assertDontSeeHtml('>Actions</th>');
    }

    public function test_a_cashier_is_not_told_about_stock_that_cost_more_than_it_sells_for(): void
    {
        $product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6]);
        Batch::factory()->for($product)->remaining(20)->create(['unit_cost' => 12]);

        Livewire::actingAs($this->cashierWhoCanBrowseProducts())->test(ProductProfile::class, ['product' => $product])
            ->call('setTab', 'inventory')
            ->assertDontSee('cost as much as or more than it sells for');

        Livewire::actingAs(User::factory()->create())->test(ProductProfile::class, ['product' => $product])
            ->call('setTab', 'inventory')
            ->assertSee('cost as much as or more than it sells for');
    }

    public function test_the_administrator_sees_costs_profit_and_the_actions_column(): void
    {
        $product = $this->productWithBatch();

        $component = Livewire::actingAs(User::factory()->create())->test(ProductProfile::class, ['product' => $product])
            ->assertSee('6.00 per');

        $component->call('setTab', 'inventory')
            ->assertSee('Unit Cost')
            ->assertSee('Est. Profit')
            ->assertSeeHtml('>Actions</th>')
            ->assertSee('Correct cost');
    }

    public function test_a_viewer_who_can_see_finances_but_cannot_correct_costs_gets_no_actions_column(): void
    {
        $product = $this->productWithBatch();
        $viewer = $this->userWith([['products', 'view'], ['inventory', 'view'], ['financials', 'view']]);

        Livewire::actingAs($viewer)->test(ProductProfile::class, ['product' => $product])
            ->call('setTab', 'inventory')
            ->assertSee('Unit Cost')
            ->assertDontSeeHtml('>Actions</th>')
            ->assertDontSee('Correct cost');
    }

    public function test_the_batch_table_empty_message_spans_only_the_columns_shown(): void
    {
        $product = Product::factory()->create();          // no batches

        Livewire::actingAs(User::factory()->create())->test(ProductProfile::class, ['product' => $product])
            ->call('setTab', 'inventory')
            ->assertSeeHtml('colspan="10"');

        Livewire::actingAs($this->cashierWhoCanBrowseProducts())->test(ProductProfile::class, ['product' => $product])
            ->call('setTab', 'inventory')
            ->assertSeeHtml('colspan="6"');
    }

    public function test_the_dashboards_finance_tiles_follow_the_same_rule(): void
    {
        $this->assertFalse(Livewire::actingAs(User::factory()->cashier()->create())->test(DashboardOverview::class)->viewData('canViewRevenue'));
        $this->assertTrue(Livewire::actingAs(User::factory()->create())->test(DashboardOverview::class)->viewData('canViewRevenue'));
    }
}
