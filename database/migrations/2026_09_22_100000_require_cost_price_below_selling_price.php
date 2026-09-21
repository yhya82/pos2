<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * A product can no longer be priced at exactly its cost: selling at cost
 * earns nothing, so the cost price must be strictly below the selling price.
 * Tightens the earlier "cost <= price" rule. Checked against existing data
 * first — no product has cost_price >= selling_price — so this applies cleanly.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_cost_not_above_price');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_cost_below_price CHECK (cost_price < selling_price)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE products DROP CONSTRAINT chk_products_cost_below_price');
        DB::statement('ALTER TABLE products ADD CONSTRAINT chk_products_cost_not_above_price CHECK (cost_price <= selling_price)');
    }
};
