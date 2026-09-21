<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Realized profit — what sold stock actually earned, as opposed to
 * v_inventory_valuation's estimate for stock still on hand. One row per
 * sold line item:
 *
 *  - revenue is what was actually charged (sale_line_items.unit_price is
 *    already the promo-effective price), less this line's share of any
 *    sale-level discount, less anything refunded on it. Tax is excluded —
 *    it was never the store's to keep.
 *  - cost is the real cost of the batches that fulfilled the line
 *    (sale_line_item_batches × batches.unit_cost), so mixed-cost stock is
 *    costed exactly. Sellable returns put units back on the shelf, so that
 *    portion of the cost is recovered; damaged returns are written off, so
 *    their cost stays as a loss.
 *  - voided sales are excluded entirely (void restores their stock). Sales
 *    flip to 'refunded' after ANY return, even a partial one, so those are
 *    kept and the return amounts are netted per line instead.
 *
 * A line's cost recovery uses the line's average unit cost, since a return
 * isn't tied to a specific batch within a line that drew from several.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("
            CREATE OR REPLACE VIEW v_realized_profit_lines AS
            SELECT
              t.*,
              (t.gross_revenue - t.sale_discount_share - t.refunded_amount)           AS net_revenue,
              (t.cost_of_goods - t.cost_recovered)                                    AS net_cost,
              (t.gross_revenue - t.sale_discount_share - t.refunded_amount)
                - (t.cost_of_goods - t.cost_recovered)                                AS profit
            FROM (
              SELECT
                sli.id                                             AS sale_line_item_id,
                s.id                                               AS sale_id,
                s.sale_date                                        AS sale_date,
                s.receipt_number                                   AS receipt_number,
                p.id                                               AS product_id,
                p.name                                             AS product_name,
                sli.quantity                                       AS quantity_sold,
                sli.subtotal                                       AS gross_revenue,
                CASE WHEN s.subtotal > 0
                     THEN ROUND(sli.subtotal / s.subtotal * s.discount_amount, 2)
                     ELSE 0 END                                    AS sale_discount_share,
                COALESCE(ret.refunded_amount, 0)                   AS refunded_amount,
                COALESCE(cst.cost, 0)                              AS cost_of_goods,
                COALESCE(ret.sellable_qty, 0)                      AS returned_sellable_qty,
                CASE WHEN sli.quantity > 0
                     THEN COALESCE(cst.cost, 0) / sli.quantity * COALESCE(ret.sellable_qty, 0)
                     ELSE 0 END                                    AS cost_recovered
              FROM sale_line_items sli
              JOIN sales s    ON s.id = sli.sale_id AND s.status <> 'voided'
              JOIN products p ON p.id = sli.product_id
              LEFT JOIN (
                SELECT slib.sale_line_item_id, SUM(slib.quantity_deducted * b.unit_cost) AS cost
                FROM sale_line_item_batches slib
                JOIN batches b ON b.id = slib.batch_id
                GROUP BY slib.sale_line_item_id
              ) cst ON cst.sale_line_item_id = sli.id
              LEFT JOIN (
                SELECT
                  srli.sale_line_item_id,
                  SUM(ROUND(srli.quantity * li.unit_price, 2))                                   AS refunded_amount,
                  SUM(CASE WHEN srli.condition_type = 'sellable' THEN srli.quantity ELSE 0 END)  AS sellable_qty
                FROM sales_return_line_items srli
                JOIN sales_returns sr  ON sr.id = srli.return_id AND sr.status = 'completed'
                JOIN sale_line_items li ON li.id = srli.sale_line_item_id
                GROUP BY srli.sale_line_item_id
              ) ret ON ret.sale_line_item_id = sli.id
            ) t
        ");
    }

    public function down(): void
    {
        DB::statement('DROP VIEW IF EXISTS v_realized_profit_lines');
    }
};
