<?php

namespace Database\Factories;

use App\Models\Product;
use App\Models\Unit;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Product>
 */
class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
            'name' => fake()->unique()->words(2, true),
            'description' => fake()->sentence(),
            'category_id' => null,
            'supplier_id' => null,
            'barcode' => fake()->unique()->ean13(),
            'purchase_unit_id' => fn () => Unit::factory(),
            'selling_unit_id' => fn () => Unit::factory(),
            'conversion_qty' => 1,
            'cost_price' => fake()->randomFloat(2, 1, 20),
            'selling_price' => fake()->randomFloat(2, 5, 50),
            'min_stock_level' => 10,
            'status' => 'active',
        ];
    }
}
