<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * v_inventory_valuation decided whether a promo was "active" with
 * CURDATE(), which follows the MySQL session timezone — on a server whose
 * clock isn't UTC that disagreed with the app's own now() (UTC) for hours
 * every day, so the same promo could count as on in the POS and off in the
 * valuation, or the reverse. UTC_DATE() doesn't depend on the session
 * timezone and matches the app's UTC clock exactly.
 *
 * This deliberately does NOT change the connection's session timezone: that
 * would shift how every existing TIMESTAMP column (created_at, audit log
 * and stock-movement times) reads back.
 *
 * Assumes config('app.timezone') stays 'UTC' — InventoryValuationTest fails
 * if the two ever stop agreeing.
 */
return new class extends Migration
{
    private const EFFECTIVE_PRICE = "
        CASE
            WHEN p.promo_discount_type = 'none' THEN p.selling_price
            WHEN (p.promo_starts_at IS NOT NULL AND UTC_DATE() < p.promo_starts_at)
              OR (p.promo_ends_at IS NOT NULL AND UTC_DATE() > p.promo_ends_at) THEN p.selling_price
            WHEN p.promo_discount_type = 'percentage'
                THEN GREATEST(0, p.selling_price - ROUND(p.selling_price * p.promo_discount_value / 100, 2))
            ELSE GREATEST(0, p.selling_price - p.promo_discount_value)
        END";

    public function up(): void
    {
        $this->replaceView(self::EFFECTIVE_PRICE);
    }

    public function down(): void
    {
        $this->replaceView(str_replace('UTC_DATE()', 'CURDATE()', self::EFFECTIVE_PRICE));
    }

    private function replaceView(string $price): void
    {
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
};
