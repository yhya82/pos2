<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Imports database/schema/pos_schema.sql verbatim — 35 tables, 23 triggers,
 * 16 views, 1 scheduled event, and bootstrap seed data (roles, permissions,
 * settings singletons, payment methods, the placeholder admin user).
 *
 * Raw SQL rather than Schema::create() migrations, deliberately: this is
 * the audited, reviewed schema from the master project file, and rewriting
 * 35 tables' worth of columns/constraints/triggers as Laravel migration
 * builder calls risks introducing a subtle mismatch the audit never saw.
 *
 * DELIMITER $$ / DELIMITER ; blocks in the source file are stripped here —
 * DELIMITER is a mysql-CLI-only convenience with no meaning to the server
 * or to PDO. The server's parser already knows a CREATE TRIGGER/PROCEDURE/
 * EVENT body ends at its own END, not at the first semicolon inside it, so
 * once the DELIMITER lines are gone and `END$$` is normalized back to a
 * plain `END;`, the whole file is valid to send in one call.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared($this->preparedSql());
    }

    public function down(): void
    {
        DB::unprepared(<<<'SQL'
            DROP EVENT IF EXISTS ev_daily_expiry_sweep;

            DROP VIEW IF EXISTS v_integrity_credit_balance_mismatch;
            DROP VIEW IF EXISTS v_integrity_line_batch_mismatch;
            DROP VIEW IF EXISTS v_integrity_completed_sales_without_payment;
            DROP VIEW IF EXISTS v_integrity_sales_without_lines;
            DROP VIEW IF EXISTS v_credit_outstanding_balances;
            DROP VIEW IF EXISTS v_product_sales_summary;
            DROP VIEW IF EXISTS v_inventory_valuation;
            DROP VIEW IF EXISTS v_out_of_stock;
            DROP VIEW IF EXISTS v_low_stock;
            DROP VIEW IF EXISTS v_refund_report;
            DROP VIEW IF EXISTS v_discount_report;
            DROP VIEW IF EXISTS v_sales_by_payment_method;
            DROP VIEW IF EXISTS v_sales_by_cashier;
            DROP VIEW IF EXISTS v_daily_sales_summary;
            DROP VIEW IF EXISTS v_batch_expiry;
            DROP VIEW IF EXISTS v_current_stock;

            SET FOREIGN_KEY_CHECKS = 0;

            DROP TABLE IF EXISTS backup_records;
            DROP TABLE IF EXISTS notification_settings;
            DROP TABLE IF EXISTS hardware_settings;
            DROP TABLE IF EXISTS module_settings;
            DROP TABLE IF EXISTS inventory_settings;
            DROP TABLE IF EXISTS security_settings;
            DROP TABLE IF EXISTS sales_settings;
            DROP TABLE IF EXISTS store_settings;
            DROP TABLE IF EXISTS general_settings;
            DROP TABLE IF EXISTS audit_logs;
            DROP TABLE IF EXISTS system_notifications;
            DROP TABLE IF EXISTS credit_transactions;
            DROP TABLE IF EXISTS return_receipts;
            DROP TABLE IF EXISTS sales_return_line_items;
            DROP TABLE IF EXISTS sales_returns;
            DROP TABLE IF EXISTS receipts;
            DROP TABLE IF EXISTS payments;
            DROP TABLE IF EXISTS sale_line_item_batches;
            DROP TABLE IF EXISTS sale_line_items;
            DROP TABLE IF EXISTS sales;
            DROP TABLE IF EXISTS payment_methods;
            DROP TABLE IF EXISTS customers;
            DROP TABLE IF EXISTS inventory_movements;
            DROP TABLE IF EXISTS batches;
            DROP TABLE IF EXISTS purchase_order_line_items;
            DROP TABLE IF EXISTS purchase_orders;
            DROP TABLE IF EXISTS products;
            DROP TABLE IF EXISTS suppliers;
            DROP TABLE IF EXISTS units;
            DROP TABLE IF EXISTS categories;
            DROP TABLE IF EXISTS login_sessions;
            DROP TABLE IF EXISTS users;
            DROP TABLE IF EXISTS role_permissions;
            DROP TABLE IF EXISTS permissions;
            DROP TABLE IF EXISTS roles;

            SET FOREIGN_KEY_CHECKS = 1;
        SQL);
    }

    private function preparedSql(): string
    {
        $sql = file_get_contents(database_path('schema/pos_schema.sql'));

        $sql = str_replace(['DELIMITER $$', 'DELIMITER ;'], '', $sql);
        $sql = str_replace('$$', ';', $sql);

        return $sql;
    }
};
