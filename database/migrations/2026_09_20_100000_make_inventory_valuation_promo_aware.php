<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v_inventory_valuation priced stock at products.selling_price (the list
 * price) even while a promotional discount was active, so the dashboard's
 * Estimated Gross Profit overstated profit during any promo. The selling
 * side now uses the same effective price the POS actually charges —
 * mirrors Product::effectiveSellingPrice()/hasActivePromo(): a promo only
 * applies inside its optional start/end window, percentage discounts round
 * to 2dp, and the price never drops below zero.
 *
 * value_at_selling_price and estimated_gross_profit keep their names (the
 * dashboard and Inventory Valuation report read them) but are now
 * promo-aware; value_at_list_price is added so the un-discounted figure is
 * still available.
 */
return new class extends Migration
{
    private const EFFECTIVE_PRICE = "
        CASE
            WHEN p.promo_discount_type = 'none' THEN p.selling_price
            WHEN (p.promo_starts_at IS NOT NULL AND CURDATE() < p.promo_starts_at)
              OR (p.promo_ends_at IS NOT NULL AND CURDATE() > p.promo_ends_at) THEN p.selling_price
            WHEN p.promo_discount_type = 'percentage'
                THEN GREATEST(0, p.selling_price - ROUND(p.selling_price * p.promo_discount_value / 100, 2))
            ELSE GREATEST(0, p.selling_price - p.promo_discount_value)
        END";

    public function up(): void
    {
        $price = self::EFFECTIVE_PRICE;

        DB::statement("
            CREATE OR REPLACE VIEW v_inventory_valuation AS
            SELECT
              p.id                                                    AS product_id,
              p.name                                                  AS product_name,
              COALESCE(SUM(b.qty_remaining), 0)                       AS qty_on_hand,
              COALESCE(SUM(b.qty_remaining * b.unit_cost), 0)         AS value_at_cost,
              COALESCE(SUM(b.qty_remaining * ({$price})), 0)          AS value_at_selling_price,
              COALESCE(SUM(b.qty_remaining * p.selling_price), 0)     AS value_at_list_price,
              COALESCE(SUM(b.qty_remaining * ({$price})), 0)
                - COALESCE(SUM(b.qty_remaining * b.unit_cost), 0)     AS estimated_gross_profit
            FROM products p
            LEFT JOIN batches b ON b.product_id = p.id AND b.status = 'active'
            WHERE p.status = 'active'
            GROUP BY p.id, p.name
        ");
    }

    public function down(): void
    {
        DB::statement("
            CREATE OR REPLACE VIEW v_inventory_valuation AS
            SELECT
              p.id                                     AS product_id,
              p.name                                   AS product_name,
              COALESCE(SUM(b.qty_remaining), 0)         AS qty_on_hand,
              COALESCE(SUM(b.qty_remaining * b.unit_cost), 0)        AS value_at_cost,
              COALESCE(SUM(b.qty_remaining * p.selling_price), 0)    AS value_at_selling_price,
              COALESCE(SUM(b.qty_remaining * p.selling_price), 0)
                - COALESCE(SUM(b.qty_remaining * b.unit_cost), 0)    AS estimated_gross_profit
            FROM products p
            LEFT JOIN batches b ON b.product_id = p.id AND b.status = 'active'
            WHERE p.status = 'active'
            GROUP BY p.id, p.name
        ");
    }
};
