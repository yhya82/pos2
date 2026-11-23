<?php

namespace Tests\Feature;

use App\Livewire\PurchaseOrders\PurchaseOrderManager;
use App\Models\Batch;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class BatchCodeSuggestionTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_the_suggestion_uses_the_products_letters_and_id_and_a_running_count(): void
    {
        $product = Product::factory()->create(['name' => 'Bel Aqua']);

        $this->assertSame("BEL{$product->id}-1", Batch::suggestCodeFor($product));

        Batch::factory()->for($product)->create();

        $this->assertSame("BEL{$product->id}-2", Batch::suggestCodeFor($product));
    }

    public function test_two_different_products_never_suggest_the_same_code_even_with_the_same_first_letters(): void
    {
        $a = Product::factory()->create(['name' => 'Bel Aqua']);
        $b = Product::factory()->create(['name' => 'Bell Pepper']);

        $this->assertNotSame(Batch::suggestCodeFor($a), Batch::suggestCodeFor($b));
    }

    public function test_punctuation_and_digits_in_the_product_name_are_dropped_from_the_prefix(): void
    {
        // Letters only, in order, then the first 3: "7-Up 500ml" -> "Upml" -> "UPM".
        $product = Product::factory()->create(['name' => '7-Up 500ml']);

        $this->assertSame("UPM{$product->id}-1", Batch::suggestCodeFor($product));
    }

    public function test_a_running_count_is_scoped_to_its_own_product(): void
    {
        $a = Product::factory()->create(['name' => 'Bel Aqua']);
        $b = Product::factory()->create(['name' => 'Coca Cola']);

        Batch::factory()->for($a)->create();
        Batch::factory()->for($a)->create();

        $this->assertSame("BEL{$a->id}-3", Batch::suggestCodeFor($a));
        $this->assertSame("COC{$b->id}-1", Batch::suggestCodeFor($b));
    }

    public function test_receiving_a_purchase_order_prefills_a_suggested_batch_code_per_line(): void
    {
        $user = User::factory()->create();
        $product = Product::factory()->create(['name' => 'Bel Aqua']);
        $supplier = Supplier::create(['name' => 'Test Supplier', 'status' => 'active']);

        $po = PurchaseOrder::create([
            'po_number' => PurchaseOrder::generatePoNumber(),
            'supplier_id' => $supplier->id,
            'status' => 'ordered',
            'order_date' => now()->toDateString(),
            'created_by' => $user->id,
        ]);
        $po->lineItems()->create([
            'product_id' => $product->id,
            'qty_ordered' => 10,
            'purchase_unit_id' => $product->purchase_unit_id,
            'cost_price' => 5,
        ]);

        Livewire::actingAs($user)->test(PurchaseOrderManager::class)
            ->call('openReceive', $po->id)
            ->assertSet('receivingLines.0.batch_code', Batch::suggestCodeFor($product))
            ->set('receivingLines.0.batch_code', 'SUPPLIER-OWN-LOT-99')
            ->assertSet('receivingLines.0.batch_code', 'SUPPLIER-OWN-LOT-99');
    }
}
