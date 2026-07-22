<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Fixes a real bug found while building Phase 08 (Notifications): the v1.1
 * low-stock and credit-limit-reached triggers, and the v1.1 expiry-sweep
 * event, all insert into system_notifications without setting
 * target_user_id or target_role_id — which the v1.2 chk_notif_has_target
 * CHECK constraint requires. Confirmed live: a batch update that crosses
 * the low-stock threshold currently fails the ENTIRE statement with a SQL
 * error (MariaDB rolls back the whole UPDATE when an AFTER UPDATE trigger
 * fails), not just skips the notification.
 *
 * pos_production_readiness.sql's own ev_daily_integrity_check already got
 * this right (broadcasting to target_role_id for the Administrator role)
 * — these three were simply never updated to match when the v1.2 CHECK
 * constraint was added. This migration brings them in line with that same
 * pattern: notify_role_id for whoever holds the Administrator role, since
 * none of these three alerts are any one specific person's concern.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_batches_low_stock_notify');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_customers_credit_limit_notify');
        DB::unprepared('DROP EVENT IF EXISTS ev_daily_expiry_sweep');

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_batches_low_stock_notify
            AFTER UPDATE ON batches
            FOR EACH ROW
            BEGIN
              DECLARE v_total DECIMAL(12,3);
              DECLARE v_min DECIMAL(12,3);
              DECLARE v_name VARCHAR(200);
              DECLARE v_existing INT;
              DECLARE v_module_enabled BOOLEAN;
              DECLARE v_category_enabled BOOLEAN;
              DECLARE v_admin_role_id BIGINT UNSIGNED;
              IF NEW.qty_remaining <> OLD.qty_remaining THEN
                SELECT COALESCE(is_enabled, FALSE) INTO v_module_enabled FROM module_settings WHERE module_name = 'notifications';
                SELECT COALESCE(is_enabled, FALSE) INTO v_category_enabled FROM notification_settings WHERE category = 'inventory';
                IF v_module_enabled = TRUE AND v_category_enabled = TRUE THEN
                  SELECT COALESCE(SUM(qty_remaining),0) INTO v_total FROM batches
                    WHERE product_id = NEW.product_id AND status = 'active';
                  SELECT min_stock_level, name INTO v_min, v_name FROM products WHERE id = NEW.product_id;
                  IF v_total <= v_min THEN
                    SELECT COUNT(*) INTO v_existing FROM system_notifications
                      WHERE category = 'inventory' AND related_table = 'products' AND related_id = NEW.product_id
                        AND created_at >= (CURRENT_TIMESTAMP - INTERVAL 1 DAY);
                    IF v_existing = 0 THEN
                      SELECT id INTO v_admin_role_id FROM roles WHERE name = 'Administrator' LIMIT 1;
                      INSERT INTO system_notifications (category, message, target_role_id, related_table, related_id)
                      VALUES ('inventory', CONCAT('Low stock: "', v_name, '" is at or below its minimum stock level (', v_total, ' remaining).'), v_admin_role_id, 'products', NEW.product_id);
                    END IF;
                  END IF;
                END IF;
              END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_customers_credit_limit_notify
            AFTER UPDATE ON customers
            FOR EACH ROW
            BEGIN
              DECLARE v_module_enabled BOOLEAN;
              DECLARE v_category_enabled BOOLEAN;
              DECLARE v_admin_role_id BIGINT UNSIGNED;
              IF NEW.credit_enabled = TRUE
                 AND NEW.outstanding_balance >= NEW.credit_limit
                 AND OLD.outstanding_balance < OLD.credit_limit THEN
                SELECT COALESCE(is_enabled, FALSE) INTO v_module_enabled FROM module_settings WHERE module_name = 'notifications';
                SELECT COALESCE(is_enabled, FALSE) INTO v_category_enabled FROM notification_settings WHERE category = 'customer';
                IF v_module_enabled = TRUE AND v_category_enabled = TRUE THEN
                  SELECT id INTO v_admin_role_id FROM roles WHERE name = 'Administrator' LIMIT 1;
                  INSERT INTO system_notifications (category, message, target_role_id, related_table, related_id)
                  VALUES ('customer', CONCAT('Customer "', NEW.name, '" has reached their credit limit.'), v_admin_role_id, 'customers', NEW.id);
                END IF;
              END IF;
            END
        SQL);

        DB::unprepared(<<<'SQL'
            CREATE EVENT IF NOT EXISTS ev_daily_expiry_sweep
            ON SCHEDULE EVERY 1 DAY STARTS (CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 1 HOUR)
            DO
            BEGIN
              UPDATE batches
              SET status = 'expired'
              WHERE status = 'active'
                AND expiry_date IS NOT NULL
                AND expiry_date < CURDATE()
                AND qty_remaining > 0;

              INSERT INTO system_notifications (category, message, target_role_id, related_table, related_id)
              SELECT
                'inventory',
                CONCAT('Batch of "', p.name, '" expires on ', b.expiry_date, ' (', DATEDIFF(b.expiry_date, CURDATE()), ' day(s)).'),
                (SELECT id FROM roles WHERE name = 'Administrator' LIMIT 1),
                'batches',
                b.id
              FROM batches b
              JOIN products p ON p.id = b.product_id
              CROSS JOIN inventory_settings s
              WHERE b.status = 'active'
                AND b.qty_remaining > 0
                AND b.expiry_date IS NOT NULL
                AND DATEDIFF(b.expiry_date, CURDATE()) IN (s.expiry_alert_days_1, s.expiry_alert_days_2, s.expiry_alert_days_3)
                AND (SELECT COALESCE(is_enabled, FALSE) FROM module_settings WHERE module_name = 'notifications') = TRUE
                AND (SELECT COALESCE(is_enabled, FALSE) FROM notification_settings WHERE category = 'inventory') = TRUE
                AND NOT EXISTS (
                  SELECT 1 FROM system_notifications n
                  WHERE n.related_table = 'batches' AND n.related_id = b.id
                    AND DATE(n.created_at) = CURDATE()
                );
            END
        SQL);
    }

    public function down(): void
    {
        // Reverting to the original (broken) trigger bodies would
        // reintroduce the bug this migration fixes, so down() just drops
        // them — matching pos_rollback_v1.2_to_v1.1.sql's own stated
        // preference to keep bug fixes even during a broader rollback.
        DB::unprepared('DROP TRIGGER IF EXISTS trg_batches_low_stock_notify');
        DB::unprepared('DROP TRIGGER IF EXISTS trg_customers_credit_limit_notify');
        DB::unprepared('DROP EVENT IF EXISTS ev_daily_expiry_sweep');
    }
};
