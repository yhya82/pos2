<?php

namespace Tests\Feature;

use App\Http\Middleware\EnsureSessionNotExpired;
use App\Livewire\AuditLogs\AuditLogViewer;
use App\Livewire\Layout\LogoutButton;
use App\Livewire\Products\ProductProfile;
use App\Livewire\PurchaseOrders\PurchaseOrderManager;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\PaymentMethod;
use App\Models\Permission;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Role;
use App\Models\Sale;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use App\Services\SaleService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\RateLimiter;
use Livewire\Livewire;
use Livewire\Volt\Volt;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * What the Administrator sees: who logged in (or failed to), who was refused
 * and what they tried, doctored requests that were ignored, every stock
 * movement, a per-person summary — in a trail nobody can edit.
 */
class AuditTrailTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    private User $clerk;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();

        $role = Role::create(['name' => 'Stock Clerk', 'description' => 'no price rights', 'status' => 'active']);
        $role->permissions()->attach(Permission::where(fn ($q) => $q
            ->where(fn ($p) => $p->where('module', 'purchase_orders')->whereIn('action', ['view', 'create', 'update']))
            ->orWhere(fn ($p) => $p->where('module', 'inventory')->whereIn('action', ['view', 'update']))
            ->orWhere(fn ($p) => $p->where('module', 'products')->where('action', 'view'))
        )->pluck('id'));
        $this->clerk = User::factory()->create(['role_id' => $role->id, 'name' => 'Clara Clerk']);
    }

    private function security(string $action)
    {
        return AuditLog::where('module', 'security')->where('action', $action);
    }

    private function attemptLogin(string $email, string $password)
    {
        return Volt::test('pages.auth.login')
            ->set('form.email', $email)
            ->set('form.password', $password)
            ->call('login');
    }

    // ----------------------------------------------------------------- logins

    public function test_a_successful_login_is_logged_with_the_user_and_ip(): void
    {
        $user = User::factory()->create();

        $this->attemptLogin($user->email, 'password');

        $log = $this->security('login')->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertSame($user->email, $log->new_value['email']);
        $this->assertNotNull($log->ip_address);
    }

    public function test_a_failed_login_on_a_known_account_is_logged_without_the_password(): void
    {
        $user = User::factory()->create();

        $this->attemptLogin($user->email, 'wrong-password-123');

        $log = $this->security('login_failed')->firstOrFail();
        $this->assertSame($user->id, $log->user_id);
        $this->assertTrue($log->new_value['known_account']);
        $this->assertSame(1, $log->new_value['attempts']);
        $this->assertStringNotContainsString('wrong-password-123', json_encode($log->new_value));
    }

    public function test_a_failed_login_for_an_unknown_email_is_logged_too(): void
    {
        $this->attemptLogin('nobody@example.com', 'whatever');

        $log = $this->security('login_failed')->firstOrFail();
        $this->assertNull($log->user_id);
        $this->assertSame('nobody@example.com', $log->new_value['email']);
        $this->assertFalse($log->new_value['known_account']);
    }

    public function test_repeated_failures_lock_the_account_and_the_lockout_is_logged(): void
    {
        $user = User::factory()->create();

        foreach (range(1, 5) as $i) {
            $this->attemptLogin($user->email, 'wrong-'.$i);
            RateLimiter::clear(strtolower($user->email).'|127.0.0.1');
        }

        $this->assertSame(1, $this->security('account_locked')->where('user_id', $user->id)->count());
        $this->assertSame(5, $this->security('login_failed')->where('user_id', $user->id)->count());

        // Even the right password is refused while locked — and that is logged as blocked.
        $this->attemptLogin($user->email, 'password');

        $blocked = $this->security('login_blocked')->firstOrFail();
        $this->assertSame('account locked', $blocked->new_value['reason']);
        $this->assertGuest();
    }

    public function test_a_deactivated_account_trying_to_log_in_is_logged(): void
    {
        $user = User::factory()->inactive()->create();

        $this->attemptLogin($user->email, 'password');

        $this->assertSame('account deactivated', $this->security('login_blocked')->firstOrFail()->new_value['reason']);
    }

    public function test_the_ip_throttle_is_logged(): void
    {
        $user = User::factory()->create();
        $key = strtolower($user->email).'|127.0.0.1';

        foreach (range(1, 5) as $i) {
            RateLimiter::hit($key);
        }

        $this->attemptLogin($user->email, 'password');

        $this->assertSame(1, $this->security('rate_limited')->count());
    }

    public function test_logging_out_is_logged(): void
    {
        Livewire::actingAs($this->clerk)->test(LogoutButton::class)->call('logout');

        $log = $this->security('logout')->firstOrFail();
        $this->assertSame($this->clerk->id, $log->user_id);
    }

    public function test_an_expired_session_is_logged(): void
    {
        // Signed in, but with no live login_sessions record for this browser session.
        $this->actingAs($this->clerk)->get(route('dashboard'))->assertRedirect(route('login'));

        $this->assertSame($this->clerk->id, $this->security('session_expired')->firstOrFail()->user_id);
    }

    // ---------------------------------------------------------- denied access

    public function test_a_refused_page_request_is_logged_with_the_path(): void
    {
        $sale = $this->makeSale($this->admin);
        $cashier = User::factory()->cashier()->create();

        $this->withoutMiddleware(EnsureSessionNotExpired::class)->actingAs($cashier)
            ->get(route('sales.receipt', $sale))->assertForbidden();

        $log = $this->security('access_denied')->firstOrFail();
        $this->assertSame($cashier->id, $log->user_id);
        $this->assertSame('GET', $log->new_value['method']);
        $this->assertStringContainsString('/receipt', $log->new_value['path']);
    }

    public function test_the_admin_only_audit_page_refusal_is_logged(): void
    {
        $this->withoutMiddleware(EnsureSessionNotExpired::class)->actingAs($this->clerk)
            ->get(route('audit-logs.index'))->assertForbidden();

        $this->assertSame(1, $this->security('access_denied')->where('user_id', $this->clerk->id)->count());
    }

    public function test_a_refused_action_inside_a_screen_names_the_component_and_the_permission(): void
    {
        $batch = Batch::factory()->for(Product::factory()->create())->remaining(5)->create();

        Livewire::actingAs($this->clerk)->test(ProductProfile::class, ['product' => $batch->product])
            ->call('openEditForm')
            ->assertForbidden();

        $log = $this->security('access_denied')->firstOrFail();
        $this->assertSame($this->clerk->id, $log->user_id);
        $this->assertSame('needs products.update', $log->new_value['why']);
        $this->assertStringContainsString('product-profile', $log->new_value['component']);
        $this->assertSame('openEditForm', $log->new_value['call']);
    }

    public function test_one_refusal_is_one_entry_not_two(): void
    {
        $batch = Batch::factory()->for(Product::factory()->create())->remaining(5)->create();

        Livewire::actingAs($this->clerk)->test(ProductProfile::class, ['product' => $batch->product])
            ->call('openEditForm');

        $this->assertSame(1, $this->security('access_denied')->count());
    }

    // ------------------------------------------------------------- tampering

    private function stockOrder(User $creator): PurchaseOrder
    {
        $unit = Unit::factory()->create();
        $product = Product::factory()->create([
            'name' => 'Tamper Widget', 'selling_price' => 10, 'cost_price' => 6, 'conversion_qty' => 1,
            'purchase_unit_id' => $unit->id, 'selling_unit_id' => $unit->id,
        ]);
        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => Supplier::create(['name' => 'Tamper Sup', 'status' => 'active'])->id,
            'status' => 'ordered', 'order_date' => now()->toDateString(), 'created_by' => $creator->id,
        ]);
        $po->lineItems()->create(['product_id' => $product->id, 'qty_ordered' => 10, 'purchase_unit_id' => $unit->id, 'cost_price' => 6]);

        return $po;
    }

    public function test_a_doctored_po_cost_is_ignored_and_logged(): void
    {
        $po = $this->stockOrder($this->admin);
        $product = $po->lineItems()->first()->product;

        Livewire::actingAs($this->clerk)->test(PurchaseOrderManager::class)
            ->call('create')
            ->set('supplierId', $po->supplier_id)
            ->set('lines.0.product_id', $product->id)
            ->set('lines.0.qty_ordered', '10')
            ->set('lines.0.cost_price', '1')                       // tampered
            ->call('save');

        $log = $this->security('tamper_ignored')->firstOrFail();
        $this->assertSame($this->clerk->id, $log->user_id);
        $this->assertStringContainsString('Tamper Widget', $log->new_value['what']);
        $this->assertEquals(1, $log->new_value['attempted']['cost_price']);
        $this->assertEquals(6, $log->new_value['applied']['cost_price']);
    }

    public function test_an_ordinary_order_by_a_clerk_logs_no_tampering(): void
    {
        $po = $this->stockOrder($this->admin);
        $product = $po->lineItems()->first()->product;

        Livewire::actingAs($this->clerk)->test(PurchaseOrderManager::class)
            ->call('create')
            ->set('supplierId', $po->supplier_id)
            ->set('lines.0.product_id', $product->id)                // cost fills in from the product
            ->set('lines.0.qty_ordered', '10')
            ->call('save');

        $this->assertSame(0, $this->security('tamper_ignored')->count());
    }

    public function test_doctored_receiving_values_and_close_short_are_ignored_and_logged(): void
    {
        $po = $this->stockOrder($this->admin);

        Livewire::actingAs($this->clerk)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->set('receivingLines.0.qty', '5')
            ->set('receivingLines.0.unit_cost', '1')
            ->set('receivingLines.0.selling_price', '99')
            ->set('receivingLines.0.close_short', true)
            ->set('receivingLines.0.short_reason', 'trying it')
            ->call('receive');

        $whats = $this->security('tamper_ignored')->get()->pluck('new_value.what')->implode(' | ');

        $this->assertStringContainsString('received cost', $whats);
        $this->assertStringContainsString('selling price', $whats);
        $this->assertStringContainsString('short', $whats);
    }

    public function test_a_normal_receipt_by_a_clerk_logs_no_tampering(): void
    {
        $po = $this->stockOrder($this->admin);

        Livewire::actingAs($this->clerk)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->set('receivingLines.0.qty', '5')
            ->call('receive');

        $this->assertSame(0, $this->security('tamper_ignored')->count());
    }

    public function test_doctored_add_stock_values_are_logged(): void
    {
        $product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6, 'conversion_qty' => 1]);

        Livewire::actingAs($this->clerk)->test(ProductProfile::class, ['product' => $product])
            ->call('openAddStockForm')
            ->set('addStockQty', '4')
            ->set('addStockUnitCost', '1')
            ->set('addStockSellingPrice', '99')
            ->call('submitAddStock');

        $log = $this->security('tamper_ignored')->firstOrFail();
        $this->assertEquals(99, $log->new_value['attempted']['selling_price']);
        $this->assertEquals(10, $log->new_value['applied']['selling_price']);
    }

    // ------------------------------------------------------- the trail itself

    public function test_the_audit_trail_cannot_be_edited_or_deleted_even_by_code(): void
    {
        AuditLog::security('login', $this->admin->id);
        $id = AuditLog::firstOrFail()->id;

        try {
            DB::table('audit_logs')->where('id', $id)->update(['action' => 'nothing_to_see']);
            $this->fail('update should be blocked');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }

        try {
            DB::table('audit_logs')->where('id', $id)->delete();
            $this->fail('delete should be blocked');
        } catch (QueryException $e) {
            $this->assertTrue(true);
        }

        $this->assertSame('login', AuditLog::findOrFail($id)->action);
    }

    public function test_pruning_never_touches_the_audit_log(): void
    {
        AuditLog::security('login', $this->admin->id);
        DB::statement("UPDATE login_sessions SET expires_at = '2000-01-01'");
        $before = AuditLog::count();

        $this->artisan('pos:prune')->assertSuccessful();

        $this->assertSame($before, AuditLog::count());
    }

    public function test_deleting_a_draft_purchase_order_records_what_was_deleted(): void
    {
        $po = $this->stockOrder($this->admin);
        $po->update(['status' => 'draft']);
        $number = $po->po_number;

        Livewire::actingAs($this->admin)->test(PurchaseOrderManager::class)
            ->call('confirmDelete', $po->id)
            ->call('delete');

        $log = AuditLog::where('action', 'delete')->where('module', 'purchase_orders')->firstOrFail();
        $this->assertSame($number, $log->previous_value['po_number']);
        $this->assertSame('Tamper Sup', $log->previous_value['supplier']);
        $this->assertSame('Tamper Widget', $log->previous_value['lines'][0]['product']);
    }

    // ----------------------------------------------------------------- viewer

    private function makeSale(User $cashier): Sale
    {
        $product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6]);
        Batch::factory()->for($product)->remaining(50)->create(['unit_cost' => 5]);

        return app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 1]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none', discountValue: 0, discountReason: null,
            cashier: $cashier,
        );
    }

    public function test_the_security_tab_lists_only_security_events_with_readable_detail(): void
    {
        $this->attemptLogin('intruder@example.com', 'guess');
        AuditLog::record('create', 'products', 'Product', 1);

        Livewire::actingAs($this->admin)->test(AuditLogViewer::class)
            ->call('setTab', 'security')
            ->assertSee('Login failed')
            ->assertSee('intruder@example.com')
            ->assertSee('no such account')
            ->assertDontSee('Products');
    }

    public function test_the_activity_tab_still_shows_everything_including_security_events(): void
    {
        $this->attemptLogin('intruder@example.com', 'guess');
        AuditLog::record('create', 'products', 'Product', 1);

        Livewire::actingAs($this->admin)->test(AuditLogViewer::class)
            ->assertSee('Login failed')
            ->assertSee('Products');
    }

    public function test_the_alert_strip_counts_the_last_24_hours_and_locked_accounts(): void
    {
        $this->attemptLogin('intruder@example.com', 'guess');
        $this->attemptLogin('intruder2@example.com', 'guess');
        AuditLog::security('access_denied', $this->clerk->id, ['path' => '/x'], 'Request');
        AuditLog::tamperIgnored('a cost', ['x' => 1], ['x' => 2]);
        User::factory()->create(['locked_until' => now()->addMinutes(10)]);

        $alerts = Livewire::actingAs($this->admin)->test(AuditLogViewer::class)->viewData('alerts');

        $this->assertSame(2, $alerts['failed_logins']);
        $this->assertSame(1, $alerts['denied']);
        $this->assertSame(1, $alerts['tampering']);
        $this->assertSame(1, $alerts['locked']);
    }

    public function test_a_quiet_day_says_so(): void
    {
        Livewire::actingAs($this->admin)->test(AuditLogViewer::class)
            ->assertSee('No failed logins, denied requests or tampering.');
    }

    public function test_who_did_what_summarises_each_person_and_flags_the_worrying_ones(): void
    {
        $cashier = User::factory()->cashier()->create(['name' => 'Carl Cashier']);
        $this->actingAs($cashier);
        $this->makeSale($cashier);                                       // a sale by Carl, while signed in

        foreach (range(1, 3) as $i) {
            AuditLog::security('access_denied', $this->clerk->id, ['path' => '/x'], 'Request');   // Clara: 3 denied
        }

        $people = Livewire::actingAs($this->admin)->test(AuditLogViewer::class)
            ->call('setTab', 'people')
            ->assertSee('Carl Cashier')
            ->assertSee('Clara Clerk')
            ->assertSee('Needs a look')
            ->viewData('people');

        $clara = $people->firstWhere('user_id', $this->clerk->id);
        $carl = $people->firstWhere('user_id', $cashier->id);

        $this->assertSame(3, (int) $clara->denied);
        $this->assertSame(1, (int) $carl->sales);
        $this->assertSame(0, (int) $carl->denied);
    }

    public function test_someone_with_a_few_failures_is_not_flagged(): void
    {
        AuditLog::security('access_denied', $this->clerk->id, ['path' => '/x'], 'Request');

        Livewire::actingAs($this->admin)->test(AuditLogViewer::class)
            ->call('setTab', 'people')
            ->assertSee('Clara Clerk')
            ->assertDontSee('Needs a look');
    }

    public function test_the_movements_tab_lists_every_stock_movement_and_can_filter(): void
    {
        $this->makeSale($this->admin);                                  // a 'sale' movement
        $po = $this->stockOrder($this->admin);
        app(\App\Services\PurchaseReceivingService::class)->receive($po, [['line_item_id' => $po->lineItems()->first()->id, 'qty' => 4]], $this->clerk);   // 'stock_received'

        $component = Livewire::actingAs($this->admin)->test(AuditLogViewer::class)
            ->call('setTab', 'movements')
            ->assertSee('Stock received')
            ->assertSee('Sale')
            ->assertSee('Tamper Widget')
            ->assertSee('Clara Clerk');

        $component->set('movementType', 'sale')
            ->assertSee('Sale')
            ->assertDontSee('Tamper Widget');
    }

    public function test_an_unknown_tab_falls_back_to_activity(): void
    {
        Livewire::actingAs($this->admin)->test(AuditLogViewer::class, ['tab' => 'nonsense'])
            ->assertSet('tab', 'activity');
    }
}
