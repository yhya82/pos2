<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Damaged/missing goods are often a few pieces out of a packet, and packets
 * with three decimals can't hold that exactly (2 of 12 pieces is 0.16667 of
 * a packet, so 2 pieces × 5.00 came out as 10.02). So:
 *
 *  - a receiving issue now records its quantity in SELLING units (pieces)
 *    at the cost per selling unit, so the loss is exactly pieces × cost;
 *    existing rows are converted from packets, and their loss_value is
 *    left untouched (it was already right).
 *  - the order line's received / damaged / closed-short quantities are kept
 *    to six decimals, so a fraction of a packet round-trips to the piece,
 *    and "accounted for" is allowed a rounding hair over the order.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE receiving_issues MODIFY unit_cost DECIMAL(14,4) UNSIGNED NOT NULL');

        DB::statement('UPDATE receiving_issues ri
            JOIN products p ON p.id = ri.product_id
            SET ri.qty = ROUND(ri.qty * p.conversion_qty, 3),
                ri.unit_cost = ROUND(ri.unit_cost / p.conversion_qty, 4)
            WHERE p.conversion_qty > 0');

        DB::statement('ALTER TABLE purchase_order_line_items DROP CONSTRAINT chk_poli_accounted_for');
        DB::statement('ALTER TABLE purchase_order_line_items DROP CONSTRAINT chk_poli_qty_received');

        DB::statement('ALTER TABLE purchase_order_line_items
            MODIFY qty_received DECIMAL(15,6) UNSIGNED NOT NULL DEFAULT 0,
            MODIFY qty_damaged DECIMAL(15,6) UNSIGNED NOT NULL DEFAULT 0,
            MODIFY qty_closed_short DECIMAL(15,6) UNSIGNED NOT NULL DEFAULT 0');

        DB::statement('ALTER TABLE purchase_order_line_items ADD CONSTRAINT chk_poli_qty_received CHECK (qty_received >= 0 AND qty_received <= qty_ordered)');
        DB::statement('ALTER TABLE purchase_order_line_items ADD CONSTRAINT chk_poli_accounted_for CHECK (qty_received + qty_damaged + qty_closed_short <= qty_ordered + 0.00001)');
    }

    public function down(): void
    {
        DB::statement('ALTER TABLE purchase_order_line_items DROP CONSTRAINT chk_poli_accounted_for');
        DB::statement('ALTER TABLE purchase_order_line_items DROP CONSTRAINT chk_poli_qty_received');

        DB::statement('ALTER TABLE purchase_order_line_items
            MODIFY qty_received DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0.000,
            MODIFY qty_damaged DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0.000,
            MODIFY qty_closed_short DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0.000');

        DB::statement('ALTER TABLE purchase_order_line_items ADD CONSTRAINT chk_poli_qty_received CHECK (qty_received >= 0 AND qty_received <= qty_ordered)');
        DB::statement('ALTER TABLE purchase_order_line_items ADD CONSTRAINT chk_poli_accounted_for CHECK (qty_received + qty_damaged + qty_closed_short <= qty_ordered)');

        DB::statement('UPDATE receiving_issues ri
            JOIN products p ON p.id = ri.product_id
            SET ri.qty = ROUND(ri.qty / p.conversion_qty, 3),
                ri.unit_cost = ROUND(ri.unit_cost * p.conversion_qty, 2)
            WHERE p.conversion_qty > 0');
        DB::statement('ALTER TABLE receiving_issues MODIFY unit_cost DECIMAL(12,2) UNSIGNED NOT NULL');
    }
};
