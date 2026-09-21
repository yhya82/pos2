<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Goods that arrive damaged, or never arrive, used to leave no trace: the
 * quantity just stayed "remaining" on the order or was received and written
 * off separately with nothing tying it to the delivery or the supplier.
 *
 *  - purchase_order_line_items gets qty_damaged (arrived, unsellable — it
 *    still uses up the ordered quantity) and qty_closed_short (will never
 *    arrive, so the line can finish). Together with qty_received they can
 *    never exceed what was ordered.
 *  - receiving_issues is the record: one row per damaged/missing report,
 *    valued at cost (the loss), with a claim against the supplier that is
 *    owed until it is credited or waived.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement('ALTER TABLE purchase_order_line_items
            ADD COLUMN qty_damaged DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0.000 AFTER qty_received,
            ADD COLUMN qty_closed_short DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0.000 AFTER qty_damaged');

        DB::statement('ALTER TABLE purchase_order_line_items
            ADD CONSTRAINT chk_poli_accounted_for CHECK (qty_received + qty_damaged + qty_closed_short <= qty_ordered)');

        DB::statement("CREATE TABLE receiving_issues (
            id                        BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            purchase_order_id         BIGINT UNSIGNED NOT NULL,
            purchase_order_line_item_id BIGINT UNSIGNED NOT NULL,
            product_id                BIGINT UNSIGNED NOT NULL,
            supplier_id               BIGINT UNSIGNED NOT NULL,
            issue_type                ENUM('damaged','missing') NOT NULL,
            qty                       DECIMAL(12,3) UNSIGNED NOT NULL,
            unit_cost                 DECIMAL(12,2) UNSIGNED NOT NULL,
            loss_value                DECIMAL(14,2) UNSIGNED NOT NULL,
            reason                    VARCHAR(255) NOT NULL,
            claim_status              ENUM('owed','credited','waived') NOT NULL DEFAULT 'owed',
            credited_amount           DECIMAL(14,2) UNSIGNED NOT NULL DEFAULT 0.00,
            resolution_note           VARCHAR(255) NULL,
            resolved_by               BIGINT UNSIGNED NULL,
            resolved_at               TIMESTAMP NULL,
            reported_by               BIGINT UNSIGNED NOT NULL,
            created_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_ri_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
            CONSTRAINT fk_ri_line FOREIGN KEY (purchase_order_line_item_id) REFERENCES purchase_order_line_items(id) ON DELETE CASCADE,
            CONSTRAINT fk_ri_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
            CONSTRAINT fk_ri_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
            CONSTRAINT fk_ri_reported_by FOREIGN KEY (reported_by) REFERENCES users(id) ON DELETE RESTRICT,
            CONSTRAINT fk_ri_resolved_by FOREIGN KEY (resolved_by) REFERENCES users(id) ON DELETE SET NULL,
            CONSTRAINT chk_ri_qty CHECK (qty > 0),
            CONSTRAINT chk_ri_credit CHECK (credited_amount <= loss_value),
            INDEX idx_ri_po (purchase_order_id),
            INDEX idx_ri_supplier (supplier_id, claim_status),
            INDEX idx_ri_created (created_at)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    public function down(): void
    {
        DB::statement('DROP TABLE IF EXISTS receiving_issues');
        DB::statement('ALTER TABLE purchase_order_line_items DROP CONSTRAINT chk_poli_accounted_for');
        DB::statement('ALTER TABLE purchase_order_line_items DROP COLUMN qty_closed_short, DROP COLUMN qty_damaged');
    }
};
