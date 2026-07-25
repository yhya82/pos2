<?php

namespace Database\Factories;

use App\Models\Customer;
use Illuminate\Database\Eloquent\Factories\Factory;

/**
 * @extends Factory<Customer>
 */
class CustomerFactory extends Factory
{
    protected $model = Customer::class;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'phone' => fake()->phoneNumber(),
            'email' => fake()->unique()->safeEmail(),
            'address' => fake()->address(),
            'credit_enabled' => false,
            'credit_limit' => 0,
            'outstanding_balance' => 0,
            'status' => 'active',
        ];
    }

    public function withCredit(float $limit): static
    {
        return $this->state(fn () => [
            'credit_enabled' => true,
            'credit_limit' => $limit,
        ]);
    }
}
