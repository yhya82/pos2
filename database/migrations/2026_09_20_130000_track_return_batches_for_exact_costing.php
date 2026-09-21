<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * v_realized_profit_lines recovered the cost of sellable returns using the
 * line's AVERAGE unit cost, because nothing recorded which batch a returned
 * unit went back into. ReturnService already knows (it restores stock batch
 * by batch); this persists it, and the view now uses each batch's own
 * unit_cost for returns that have it.
 *
 * Returns made before this migration have no rows here and keep the
 * average-cost fallback, so historical figures don't change.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('sales_return_line_item_batches', function (Blueprint $table) {
            $table->id();
            $table->foreignId('return_line_item_id')->constrained('sales_return_line_items')->cascadeOnDelete();
            $table->foreignId('batch_id')->constrained('batches')->restrictOnDelete();
            $table->decimal('quantity', 12, 3)->unsigned();

            $table->index('return_line_item_id');
            $table->index('batch_id');
        });

        $this->replaceView(exact: true);
    }

    public function down(): void
    {
        $this->replaceView(exact: false);

        Schema::dropIfExists('sales_return_line_item_batches');
    }

    private function replaceView(bool $exact): void
    {
        // With exact costing: cost recovered = the returned units' own batch
        // costs, plus the old average-cost estimate only for return lines
        // that have no batch rows (pre-migration history).
        $recovered = $exact
            ? 'COALESCE(ret.exact_recovered_cost, 0)
               + CASE WHEN sli.quantity > 0
                      THEN COALESCE(cst.cost, 0) / sli.quantity * COALESCE(ret.legacy_sellable_qty, 0)
                      ELSE 0 END'
            : 'CASE WHEN sli.quantity > 0
                    THEN COALESCE(cst.cost, 0) / sli.quantity * COALESCE(ret.sellable_qty, 0)
                    ELSE 0 END';

        $retExtras = $exact
            ? ",
                  SUM(CASE WHEN srli.condition_type = 'sellable' THEN COALESCE(al.cost, 0) ELSE 0 END) AS exact_recovered_cost,
                  SUM(CASE WHEN srli.condition_type = 'sellable' AND al.return_line_item_id IS NULL
                           THEN srli.quantity ELSE 0 END)                                                AS legacy_sellable_qty"
            : '';

        $retJoin = $exact
            ? 'LEFT JOIN (
                    SELECT srlib.return_line_item_id, SUM(srlib.quantity * b2.unit_cost) AS cost
                    FROM sales_return_line_item_batches srlib
                    JOIN batches b2 ON b2.id = srlib.batch_id
                    GROUP BY srlib.return_line_item_id
                  ) al ON al.return_line_item_id = srli.id'
            : '';

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
                {$recovered}                                       AS cost_recovered
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
                  SUM(CASE WHEN srli.condition_type = 'sellable' THEN srli.quantity ELSE 0 END)  AS sellable_qty{$retExtras}
                FROM sales_return_line_items srli
                JOIN sales_returns sr   ON sr.id = srli.return_id AND sr.status = 'completed'
                JOIN sale_line_items li ON li.id = srli.sale_line_item_id
                {$retJoin}
                GROUP BY srli.sale_line_item_id
              ) ret ON ret.sale_line_item_id = sli.id
            ) t
        ");
    }
};
