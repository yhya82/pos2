<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Business rules for product prices, enforced in the database as well as in
 * the forms so nothing that writes to `products` directly (receiving stock
 * syncing the reference cost, imports, tinker) can break them:
 *
 *  - a selling price must be above zero (it was ">= 0", which allowed free items)
 *  - a product's cost price can't exceed its selling price
 *
 * Both were checked against existing data first — no product violates
 * either — so this applies cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_selling_price');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_selling_price CHECK (selling_price > 0)');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_cost_not_above_price CHECK (cost_price <= selling_price)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_cost_not_above_price');
        DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_selling_price');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_selling_price CHECK (selling_price >= 0)');
    }
};
