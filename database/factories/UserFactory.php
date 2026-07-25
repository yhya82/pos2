<?php

namespace Database\Factories;

use App\Models\Role;
use App\Models\User;
use Illuminate\Database\Eloquent\Factories\Factory;
use Illuminate\Support\Facades\Hash;

/**
 * @extends Factory<User>
 *
 * Rewritten for this project's actual `users` schema — the stock Breeze
 * factory referenced `password`, `email_verified_at`, and `remember_token`,
 * none of which exist here (password_hash, role_id NOT NULL, status,
 * failed_login_attempts/locked_until instead). `role_id` defaults to the
 * Administrator role the schema's own bootstrap seed always creates, since
 * every user needs one and most tests don't care which.
 */
class UserFactory extends Factory
{
    protected static ?string $passwordHash;

    public function definition(): array
    {
        return [
            'name' => fake()->name(),
            'username' => fake()->unique()->userName(),
            'email' => fake()->unique()->safeEmail(),
            'password_hash' => static::$passwordHash ??= Hash::make('password'),
            'role_id' => fn () => Role::where('name', 'Administrator')->value('id'),
            'status' => 'active',
        ];
    }

    public function cashier(): static
    {
        return $this->state(fn () => [
            'role_id' => fn () => Role::where('name', 'Cashier')->value('id'),
        ]);
    }

    public function inactive(): static
    {
        return $this->state(fn (array $attributes) => [
            'status' => 'inactive',
        ]);
    }
}
