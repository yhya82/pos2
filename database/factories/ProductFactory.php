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
            'selling_price' => fake()->randomFloat(2, 5, 50),
            // Derived from whatever selling_price ends up being (including a
            // test's override) — the DB rejects cost above price, so an
            // independently random cost would fail tests at random.
            'cost_price' => fn (array $attributes) => round((float) $attributes['selling_price'] * 0.6, 2),
            'min_stock_level' => 10,
            'status' => 'active',
        ];
    }
}
