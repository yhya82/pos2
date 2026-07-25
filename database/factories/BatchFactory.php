<?php

namespace Database\Factories;

use App\Models\Batch;
use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Batch>
 */
class BatchFactory extends Factory
{
    protected $model = Batch::class;

    public function definition(): array
    {
        $qty = fake()->randomFloat(3, 50, 200);

        return [
            'product_id' => fn () => Product::factory(),
            'batch_code' => fake()->unique()->bothify('BATCH-####'),
            'qty_received' => $qty,
            'qty_remaining' => $qty,
            'unit_cost' => fake()->randomFloat(2, 1, 20),
            'expiry_date' => null,
            'received_date' => now()->toDateString(),
            'status' => 'active',
        ];
    }

    public function expiringOn(string $date): static
    {
        return $this->state(fn () => ['expiry_date' => $date]);
    }

    public function remaining(float $qty): static
    {
        return $this->state(fn () => ['qty_remaining' => $qty]);
    }
}
