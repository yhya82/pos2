<?php

namespace Tests\Feature;

use App\Livewire\Products\ProductManager;
use App\Models\Category;
use App\Models\Product;
use App\Models\Supplier;
use App\Models\Unit;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class ProductManagerTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private function validFormState(): array
    {
        $category = Category::create(['name' => 'Test Category', 'status' => 'active']);
        $supplier = Supplier::create(['name' => 'Test Supplier', 'status' => 'active']);
        $unit = Unit::factory()->create();

        return [
            'name' => 'Test Product',
            'categoryId' => $category->id,
            'supplierId' => $supplier->id,
            'purchaseUnitId' => $unit->id,
            'sellingUnitId' => $unit->id,
            'sellingPrice' => '10.00',
        ];
    }

    // ------------------------------------------------------- image upload

    public function test_an_invalid_image_mime_is_rejected_before_saving(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create();

        $component = Livewire::actingAs($admin)->test(ProductManager::class)
            ->call('create')
            ->set($this->validFormState())
            ->set('photo', UploadedFile::fake()->create('not-an-image.txt', 10, 'text/plain'))
            ->call('save');

        $component->assertHasErrors('photo');
        $this->assertSame(0, Product::count());
        Storage::disk('public')->assertDirectoryEmpty('products');
    }

    public function test_an_oversized_image_is_rejected(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create();

        $component = Livewire::actingAs($admin)->test(ProductManager::class)
            ->call('create')
            ->set($this->validFormState())
            ->set('photo', UploadedFile::fake()->image('big.jpg')->size(3000))
            ->call('save');

        $component->assertHasErrors('photo');
        $this->assertSame(0, Product::count());
    }

    public function test_a_valid_image_is_accepted_and_stored(): void
    {
        Storage::fake('public');
        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(ProductManager::class)
            ->call('create')
            ->set($this->validFormState())
            ->set('photo', UploadedFile::fake()->image('photo.jpg'))
            ->call('save')
            ->assertHasNoErrors();

        $product = Product::where('name', 'Test Product')->firstOrFail();

        $this->assertNotNull($product->image_path);
        Storage::disk('public')->assertExists($product->image_path);
    }

    // -------------------------------------------- stale preview (wire:key)

    /**
     * The photo field's wire:key used to be "photo-field-new" for every
     * single "Add Product" click (editingProductId is always null then),
     * so Livewire never tore that DOM node — and its Alpine-side image
     * preview — down between separate add-product sessions.
     */
    public function test_the_photo_field_key_changes_between_separate_create_sessions(): void
    {
        $admin = User::factory()->create();

        $component = Livewire::actingAs($admin)->test(ProductManager::class)
            ->call('create');

        $firstKey = $component->get('photoFieldKey');
        $firstHtml = $component->html();

        $component->call('create');

        $secondKey = $component->get('photoFieldKey');

        $this->assertNotSame($firstKey, $secondKey);
        $this->assertStringNotContainsString(
            "photo-field-new-{$firstKey}",
            $component->html(),
        );
        $this->assertStringContainsString("photo-field-new-{$secondKey}", $component->html());
    }
}
