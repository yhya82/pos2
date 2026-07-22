-- ============================================================================
-- Mini Market POS System — Production MySQL Schema
-- Version: 1.3 (Laravel-compatibility revision — see notes below)
-- Engine: InnoDB | Charset: utf8mb4 | Collation: utf8mb4_unicode_ci
-- Target: MySQL 8.0.16+ (uses JSON, CHECK constraints, EVENTS, cursors in
-- triggers — the preflight block below refuses to run below 8.0.16)
--
-- IMPLEMENTATION NOTE (added when importing into this Laravel project):
-- the only local server available in this environment is MariaDB 10.4.32
-- (XAMPP's bundled default), not MySQL 8.0.16+. Everything below is
-- byte-for-byte the schema reviewed in the master project file EXCEPT the
-- preflight version-check procedure in Section "PREFLIGHT", which was
-- widened to recognize MariaDB on its own terms (it has enforced CHECK
-- constraints since 10.2.1 — the exact guarantee the MySQL 8.0.16 floor
-- exists for) rather than comparing MariaDB's version numbers against a
-- MySQL-specific threshold they were never meant to be measured against.
--
-- This script implements the entities and rules resolved in the
-- Requirements Analysis document (pos-requirements-analysis.md), including:
--   - Batch/lot-level expiry tracking
--   - Purchase Management (in scope)
--   - Single role per user
--   - Single payment method per sale (no split tender)
--   - Loyalty Program excluded
--   - Polymorphic, immutable Audit Log
--   - Soft-delete (status flags) instead of physical delete on master data
--
-- v1.1 additions: password_reset_tokens, security_settings + account
-- lockout columns, per-line discount attribution, return_receipts,
-- credit-requires-customer trigger, batch auto-depleted trigger, low-stock
-- and credit-limit notification triggers, daily expiry sweep event, 10
-- reporting views, 2 data-integrity monitoring views.
--
-- v1.2 additions (closing findings from pos_schema_audit.md; all additive):
--   - MySQL-version preflight check (CHECK constraints no-op below 8.0.16)
--   - Bootstrap seed data: default roles, permission catalog, role grants,
--     initial admin user (previously the schema could not log in at all)
--   - sales.tax_amount; purchase_orders.approved_by/approved_at;
--     units.is_active; FULLTEXT index on products.name
--   - notifications: CHECK requiring a target, composite (related_table,
--     related_id) index
--   - Indexes: credit_transactions.sale_id, sales_returns.status/processed_by
--   - trg_sales_no_delete / trg_purchase_orders_restrict_delete: financial
--     and purchasing history can no longer be physically deleted
--   - trg_sales_void_reverses_inventory: voiding a sale now automatically
--     restores batch quantities, logs the compensating movement, and
--     reverses any credit-balance charge
--   - trg_sales_returns_sync_status_ins/upd: a completed return now flips
--     the parent sale's status to 'refunded' automatically
--   - trg_payment_methods_code_immutable: the 'credit' code the
--     credit-sale-requires-customer trigger depends on can't be silently
--     renamed
--   - 8 settings-table AFTER UPDATE triggers auto-writing to audit_logs
--   - Fixed: the v1.1 notification triggers/event now check
--     module_settings('notifications') and notification_settings(category)
--     before inserting — v1.1 shipped these ignoring that toggle entirely
--   - 2 new integrity views: line-vs-batch quantity mismatch, stored vs.
--     ledger credit-balance mismatch
--
-- v1.3 additions (Laravel-compatibility revision — you told us you're
-- building on Laravel; these three table names collide with Laravel's OWN
-- default tables of the same name but a DIFFERENT structure, which would
-- break on `php artisan migrate` or Laravel's built-in auth features if
-- left as-is):
--   - `sessions` renamed to `login_sessions` throughout (table, FK, indexes)
--     — Laravel's 'database' session driver expects its own `sessions`
--     table with a different shape; this project's table is for
--     application-level login-session tracking, not framework session
--     storage, so it needed a name that doesn't collide
--   - `notifications` renamed to `system_notifications` throughout (table,
--     FK, CHECK, indexes, all trigger/event bodies that write to it) —
--     Laravel's native notifications table uses a different polymorphic
--     shape (notifiable_type/notifiable_id/data JSON) driven by
--     Notification classes; this project's triggers write directly with a
--     fixed category/message shape, which is incompatible with Laravel's
--     native table, so it needed its own name instead of fighting Laravel's
--     convention
--   - `password_reset_tokens` REMOVED entirely — Laravel ships a complete,
--     secure password-reset system built around a table of exactly this
--     name (email/token/created_at); recommendation is to use Laravel's
--     built-in system rather than maintain a parallel one. If you are NOT
--     using Laravel, reintroduce this table (see the note left in its
--     place, Section 1) with the shape this revision removed
--   - Bootstrap admin seed: email changed from NULL to a real placeholder,
--     since Laravel's password broker looks users up BY email to send a
--     reset link — a NULL email would make the seeded admin unrecoverable
--     via Laravel's standard reset flow
--
-- REQUIRES: event_scheduler must be enabled for the daily expiry sweep to run:
--   SET GLOBAL event_scheduler = ON;
-- (Set this in your MySQL server config for it to persist across restarts —
-- see [mysqld] section: event_scheduler=ON)
--
-- Table creation order respects foreign key dependencies.
-- ============================================================================

SET NAMES utf8mb4;
SET FOREIGN_KEY_CHECKS = 1;

-- ============================================================================
-- PREFLIGHT: version check (finding 2.1, widened for MariaDB — see the
-- implementation note at the top of this file)
-- CHECK constraints are parsed but SILENTLY IGNORED on MySQL < 8.0.16 — every
-- business rule expressed as a CHECK in this schema (stock >= 0, credit
-- limit, singleton settings, etc.) would appear to work in testing and then
-- let bad data in on an under-versioned production server. MariaDB has
-- actually enforced CHECK constraints since 10.2.1, so it is checked
-- against its own floor instead of MySQL's.
-- ============================================================================

DELIMITER $$
CREATE PROCEDURE _pos_schema_preflight_version_check()
BEGIN
  DECLARE v_version_string VARCHAR(100);
  DECLARE v_major INT;
  DECLARE v_minor INT;
  DECLARE v_patch INT;
  SET v_version_string = VERSION();
  IF v_version_string LIKE '%MariaDB%' THEN
    SET v_major = CAST(SUBSTRING_INDEX(v_version_string, '.', 1) AS UNSIGNED);
    SET v_minor = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(v_version_string, '.', 2), '.', -1) AS UNSIGNED);
    IF v_major < 10 OR (v_major = 10 AND v_minor < 2) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'This schema requires MariaDB 10.2 or later — CHECK constraints are not enforced on older versions. Upgrade before deploying.';
    END IF;
  ELSE
    SET v_major = CAST(SUBSTRING_INDEX(v_version_string, '.', 1) AS UNSIGNED);
    SET v_minor = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(v_version_string, '.', 2), '.', -1) AS UNSIGNED);
    SET v_patch = CAST(SUBSTRING_INDEX(SUBSTRING_INDEX(v_version_string, '.', 3), '.', -1) AS UNSIGNED);
    IF v_major < 8 OR (v_major = 8 AND v_minor = 0 AND v_patch < 16) THEN
      SIGNAL SQLSTATE '45000'
        SET MESSAGE_TEXT = 'This schema requires MySQL 8.0.16 or later — CHECK constraints are silently ignored on older versions. Upgrade before deploying.';
    END IF;
  END IF;
END$$
DELIMITER ;

CALL _pos_schema_preflight_version_check();
DROP PROCEDURE _pos_schema_preflight_version_check;

-- ============================================================================
-- SECTION 1: IDENTITY, ROLES & PERMISSIONS  (traces to R1, R2, R3)
-- ============================================================================

CREATE TABLE roles (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(100)  NOT NULL,
  description   VARCHAR(255)  NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_roles_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE permissions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module        VARCHAR(100) NOT NULL,
  action        ENUM('view','create','update','delete') NOT NULL,
  description   VARCHAR(255) NULL,
  UNIQUE KEY uq_permissions_module_action (module, action)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE role_permissions (
  role_id       BIGINT UNSIGNED NOT NULL,
  permission_id BIGINT UNSIGNED NOT NULL,
  granted_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  PRIMARY KEY (role_id, permission_id),
  CONSTRAINT fk_role_permissions_role
    FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT fk_role_permissions_permission
    FOREIGN KEY (permission_id) REFERENCES permissions(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE users (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name           VARCHAR(150) NOT NULL,
  username       VARCHAR(100) NOT NULL,
  email          VARCHAR(150) NULL,
  phone          VARCHAR(30)  NULL,
  password_hash  VARCHAR(255) NOT NULL,
  role_id        BIGINT UNSIGNED NOT NULL,          -- single role per user (confirmed)
  status         ENUM('active','inactive') NOT NULL DEFAULT 'active',
  last_login_at  DATETIME NULL,
  failed_login_attempts  SMALLINT UNSIGNED NOT NULL DEFAULT 0,   -- v1.1: paired with security_settings lockout policy
  locked_until            DATETIME NULL,                          -- v1.1: set when attempts exceed security_settings.max_failed_login_attempts
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_users_username (username),
  UNIQUE KEY uq_users_email (email),
  CONSTRAINT fk_users_role FOREIGN KEY (role_id) REFERENCES roles(id) ON DELETE RESTRICT,
  INDEX idx_users_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE login_sessions (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id       BIGINT UNSIGNED NOT NULL,
  token_hash    CHAR(64) NOT NULL,                  -- SHA-256 hash of session token
  ip_address    VARCHAR(45) NULL,
  user_agent    VARCHAR(255) NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  expires_at    DATETIME NOT NULL,
  revoked_at    DATETIME NULL,
  UNIQUE KEY uq_login_sessions_token (token_hash),
  CONSTRAINT fk_login_sessions_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
  INDEX idx_login_sessions_user (user_id),
  INDEX idx_login_sessions_expiry (expires_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Password reset: intentionally NOT modeled here as of this revision.
-- If you're integrating with Laravel, use Laravel's own password-reset
-- system (Illuminate\Auth\Passwords\PasswordBroker), which creates and
-- manages its own `password_reset_tokens` table (email, token, created_at)
-- automatically via `php artisan install:api`/the default auth scaffolding.
-- It's secure, tested, and free — building a parallel custom table here
-- would only collide with it and add solo-maintenance burden for no
-- benefit. If you're NOT using Laravel, reintroduce a table here with the
-- same shape this revision removed: id, user_id, token_hash, requested_ip,
-- expires_at, used_at, created_at.

-- ============================================================================
-- SECTION 2: CATALOG — CATEGORIES, UNITS, SUPPLIERS, PRODUCTS (R4, R5, R10)
-- ============================================================================

CREATE TABLE categories (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  description   VARCHAR(255) NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_categories_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE units (
  id     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name   VARCHAR(50) NOT NULL,                      -- e.g. 'Carton', 'Bottle', 'Bag', 'Kg'
  is_active BOOLEAN NOT NULL DEFAULT TRUE,            -- v1.2: soft-delete consistency (finding 7)
  UNIQUE KEY uq_units_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE suppliers (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name          VARCHAR(150) NOT NULL,
  phone         VARCHAR(30)  NULL,
  email         VARCHAR(150) NULL,
  address       VARCHAR(255) NULL,
  notes         TEXT NULL,
  status        ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  INDEX idx_suppliers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Product <-> Supplier is many-to-one (one supplier supplies many products) — confirmed, no junction table.
CREATE TABLE products (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name              VARCHAR(200) NOT NULL,
  description       TEXT NULL,
  category_id       BIGINT UNSIGNED NULL,
  supplier_id       BIGINT UNSIGNED NULL,
  barcode           VARCHAR(64) NULL,
  purchase_unit_id  BIGINT UNSIGNED NOT NULL,
  selling_unit_id   BIGINT UNSIGNED NOT NULL,
  conversion_qty    DECIMAL(10,3) NOT NULL DEFAULT 1.000,   -- 1 purchase_unit = conversion_qty selling_units
  cost_price        DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,  -- reference/last cost
  selling_price     DECIMAL(12,2) UNSIGNED NOT NULL,
  min_stock_level   DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0.000,
  status            ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_products_barcode (barcode),          -- InnoDB unique index allows multiple NULLs
  CONSTRAINT fk_products_category FOREIGN KEY (category_id) REFERENCES categories(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE SET NULL,
  CONSTRAINT fk_products_purchase_unit FOREIGN KEY (purchase_unit_id) REFERENCES units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_products_selling_unit FOREIGN KEY (selling_unit_id) REFERENCES units(id) ON DELETE RESTRICT,
  CONSTRAINT chk_products_conversion_qty CHECK (conversion_qty > 0),
  CONSTRAINT chk_products_selling_price CHECK (selling_price >= 0),
  CONSTRAINT chk_products_min_stock CHECK (min_stock_level >= 0),
  INDEX idx_products_name (name),
  FULLTEXT INDEX ft_products_name (name),            -- v1.2: substring/word search for POS product lookup
  INDEX idx_products_category (category_id),
  INDEX idx_products_supplier (supplier_id),
  INDEX idx_products_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 3: PURCHASE MANAGEMENT (R11a) — confirmed in scope
-- ============================================================================

CREATE TABLE purchase_orders (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  po_number     VARCHAR(50) NOT NULL,
  supplier_id   BIGINT UNSIGNED NOT NULL,
  status        ENUM('draft','ordered','partially_received','received','cancelled') NOT NULL DEFAULT 'draft',
  order_date    DATE NOT NULL,
  created_by    BIGINT UNSIGNED NOT NULL,
  approved_by   BIGINT UNSIGNED NULL,                -- v1.2: optional approval gate (finding 1.3) — nullable, app decides if required
  approved_at   DATETIME NULL,
  created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_purchase_orders_number (po_number),
  CONSTRAINT fk_po_supplier FOREIGN KEY (supplier_id) REFERENCES suppliers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_po_created_by FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_po_approved_by FOREIGN KEY (approved_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_po_supplier (supplier_id),
  INDEX idx_po_status (status),
  INDEX idx_po_order_date (order_date)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE purchase_order_line_items (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  purchase_order_id   BIGINT UNSIGNED NOT NULL,
  product_id          BIGINT UNSIGNED NOT NULL,
  qty_ordered         DECIMAL(12,3) UNSIGNED NOT NULL,
  qty_received        DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 0.000,
  purchase_unit_id    BIGINT UNSIGNED NOT NULL,
  cost_price          DECIMAL(12,2) UNSIGNED NOT NULL,
  created_at          TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_poli_po FOREIGN KEY (purchase_order_id) REFERENCES purchase_orders(id) ON DELETE CASCADE,
  CONSTRAINT fk_poli_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_poli_unit FOREIGN KEY (purchase_unit_id) REFERENCES units(id) ON DELETE RESTRICT,
  CONSTRAINT chk_poli_qty_ordered CHECK (qty_ordered > 0),
  CONSTRAINT chk_poli_qty_received CHECK (qty_received >= 0 AND qty_received <= qty_ordered),
  INDEX idx_poli_po (purchase_order_id),
  INDEX idx_poli_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 4: BATCH-LEVEL INVENTORY (R8a, R8b) — confirmed batch/lot expiry tracking
-- ============================================================================

CREATE TABLE batches (
  id                            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id                    BIGINT UNSIGNED NOT NULL,
  purchase_order_line_item_id   BIGINT UNSIGNED NULL,     -- source of this batch; nullable for manual/opening stock
  batch_code                    VARCHAR(50) NULL,
  qty_received                  DECIMAL(12,3) UNSIGNED NOT NULL,
  qty_remaining                 DECIMAL(12,3) UNSIGNED NOT NULL,
  unit_cost                     DECIMAL(12,2) UNSIGNED NOT NULL,
  expiry_date                   DATE NULL,
  received_date                 DATE NOT NULL,
  status                        ENUM('active','depleted','expired','written_off') NOT NULL DEFAULT 'active',
  created_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at                    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT fk_batches_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_batches_poli FOREIGN KEY (purchase_order_line_item_id) REFERENCES purchase_order_line_items(id) ON DELETE SET NULL,
  CONSTRAINT chk_batches_qty_remaining CHECK (qty_remaining >= 0 AND qty_remaining <= qty_received),
  INDEX idx_batches_product (product_id),
  INDEX idx_batches_expiry (expiry_date),
  INDEX idx_batches_product_expiry (product_id, expiry_date),   -- supports FEFO lookups
  INDEX idx_batches_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Append-only ledger of every stock-changing event (R6, R7, R9)
CREATE TABLE inventory_movements (
  id                BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  product_id        BIGINT UNSIGNED NOT NULL,
  batch_id          BIGINT UNSIGNED NULL,
  movement_type     ENUM('stock_received','sale','return','damaged','expired','adjustment') NOT NULL,
  quantity          DECIMAL(12,3) NOT NULL,          -- signed: positive = increase, negative = decrease
  previous_qty      DECIMAL(12,3) UNSIGNED NOT NULL,
  new_qty           DECIMAL(12,3) UNSIGNED NOT NULL,
  reference_table   VARCHAR(50) NULL,                -- e.g. 'sales', 'sales_returns', 'purchase_orders'
  reference_id      BIGINT UNSIGNED NULL,
  reason            VARCHAR(255) NULL,
  user_id           BIGINT UNSIGNED NOT NULL,
  created_at        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_im_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_im_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE RESTRICT,
  CONSTRAINT fk_im_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_im_product_date (product_id, created_at),
  INDEX idx_im_batch (batch_id),
  INDEX idx_im_type (movement_type),
  INDEX idx_im_reference (reference_table, reference_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 5: CUSTOMERS & CREDIT (R15)
-- ============================================================================

CREATE TABLE customers (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name                  VARCHAR(150) NOT NULL,
  phone                 VARCHAR(30) NULL,
  email                 VARCHAR(150) NULL,
  address               VARCHAR(255) NULL,
  credit_enabled        BOOLEAN NOT NULL DEFAULT FALSE,
  credit_limit          DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
  outstanding_balance   DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
  status                ENUM('active','inactive') NOT NULL DEFAULT 'active',
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  updated_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_customers_balance CHECK (outstanding_balance <= credit_limit OR credit_enabled = FALSE),
  INDEX idx_customers_phone (phone),
  INDEX idx_customers_name (name)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 6: SALES / POS (R11, R12, R13, R14)
-- ============================================================================

CREATE TABLE payment_methods (
  id          BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  name        VARCHAR(50) NOT NULL,
  code        VARCHAR(30) NOT NULL,               -- 'cash', 'mobile_money', 'bank_transfer', 'credit'
  is_enabled  BOOLEAN NOT NULL DEFAULT TRUE,
  UNIQUE KEY uq_payment_methods_code (code)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales (
  id                    BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  receipt_number        VARCHAR(50) NOT NULL,
  sale_date             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  cashier_id            BIGINT UNSIGNED NOT NULL,
  customer_id           BIGINT UNSIGNED NULL,          -- required (app-enforced) when a credit sale
  subtotal              DECIMAL(14,2) UNSIGNED NOT NULL DEFAULT 0.00,
  discount_type         ENUM('none','fixed','percentage') NOT NULL DEFAULT 'none',
  discount_value        DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
  discount_amount       DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
  discount_reason       VARCHAR(255) NULL,
  discount_applied_by   BIGINT UNSIGNED NULL,
  tax_amount            DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,   -- v1.2: actual tax charged, frozen at sale time (finding 1.2)
  total_amount          DECIMAL(14,2) UNSIGNED NOT NULL,
  status                ENUM('completed','voided','refunded') NOT NULL DEFAULT 'completed',
  voided_by             BIGINT UNSIGNED NULL,
  voided_at             DATETIME NULL,
  void_reason           VARCHAR(255) NULL,
  created_at            TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_receipt_number (receipt_number),
  CONSTRAINT fk_sales_cashier FOREIGN KEY (cashier_id) REFERENCES users(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sales_discount_by FOREIGN KEY (discount_applied_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_voided_by FOREIGN KEY (voided_by) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_sales_date (sale_date),
  INDEX idx_sales_cashier_date (cashier_id, sale_date),
  INDEX idx_sales_customer (customer_id),
  INDEX idx_sales_status (status)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sale_line_items (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id                BIGINT UNSIGNED NOT NULL,
  product_id             BIGINT UNSIGNED NOT NULL,
  selling_unit_id        BIGINT UNSIGNED NOT NULL,
  quantity               DECIMAL(12,3) UNSIGNED NOT NULL,
  unit_price              DECIMAL(12,2) UNSIGNED NOT NULL,
  line_discount_type       ENUM('none','fixed','percentage') NOT NULL DEFAULT 'none',   -- v1.1: mirrors header-level attribution
  line_discount_amount    DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
  line_discount_reason     VARCHAR(255) NULL,                                            -- v1.1
  line_discount_applied_by BIGINT UNSIGNED NULL,                                          -- v1.1
  subtotal               DECIMAL(14,2) UNSIGNED NOT NULL,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_sli_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_sli_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sli_unit FOREIGN KEY (selling_unit_id) REFERENCES units(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sli_discount_by FOREIGN KEY (line_discount_applied_by) REFERENCES users(id) ON DELETE SET NULL,
  CONSTRAINT chk_sli_quantity CHECK (quantity > 0),
  INDEX idx_sli_sale (sale_id),
  INDEX idx_sli_product (product_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Records exactly which batch(es) fulfilled a sale line — required for FEFO,
-- COGS accuracy, and expiry-safe traceability.
CREATE TABLE sale_line_item_batches (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_line_item_id    BIGINT UNSIGNED NOT NULL,
  batch_id             BIGINT UNSIGNED NOT NULL,
  quantity_deducted    DECIMAL(12,3) UNSIGNED NOT NULL,
  CONSTRAINT fk_slib_sli FOREIGN KEY (sale_line_item_id) REFERENCES sale_line_items(id) ON DELETE CASCADE,
  CONSTRAINT fk_slib_batch FOREIGN KEY (batch_id) REFERENCES batches(id) ON DELETE RESTRICT,
  CONSTRAINT chk_slib_qty CHECK (quantity_deducted > 0),
  INDEX idx_slib_sli (sale_line_item_id),
  INDEX idx_slib_batch (batch_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Exactly one payment per sale — confirmed: no split tender.
CREATE TABLE payments (
  id                  BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id             BIGINT UNSIGNED NOT NULL,
  payment_method_id   BIGINT UNSIGNED NOT NULL,
  amount              DECIMAL(14,2) UNSIGNED NOT NULL,
  reference_number    VARCHAR(100) NULL,           -- e.g. mobile money transaction ID
  received_by         BIGINT UNSIGNED NOT NULL,
  paid_at             DATETIME NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_payments_sale (sale_id),            -- enforces the 1:1 cardinality
  CONSTRAINT fk_payments_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE,
  CONSTRAINT fk_payments_method FOREIGN KEY (payment_method_id) REFERENCES payment_methods(id) ON DELETE RESTRICT,
  CONSTRAINT fk_payments_received_by FOREIGN KEY (received_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_payments_method (payment_method_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Persisted, immutable snapshot — decoupled from live product/price changes.
CREATE TABLE receipts (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  sale_id                BIGINT UNSIGNED NOT NULL,
  receipt_number         VARCHAR(50) NOT NULL,
  business_snapshot      JSON NOT NULL,
  line_items_snapshot    JSON NOT NULL,
  totals_snapshot        JSON NOT NULL,
  print_status           ENUM('not_printed','printed') NOT NULL DEFAULT 'not_printed',
  printed_at             DATETIME NULL,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_receipts_sale (sale_id),
  UNIQUE KEY uq_receipts_number (receipt_number),
  CONSTRAINT fk_receipts_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 7: RETURNS (R16) — configurable module
-- Table names use "sales_returns" (not "returns") because RETURNS is a
-- reserved word in MySQL (used in stored function syntax).
-- ============================================================================

CREATE TABLE sales_returns (
  id                 BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_number      VARCHAR(50) NOT NULL,
  original_sale_id   BIGINT UNSIGNED NOT NULL,
  processed_by       BIGINT UNSIGNED NOT NULL,
  reason             VARCHAR(255) NOT NULL,
  refund_amount      DECIMAL(12,2) UNSIGNED NOT NULL DEFAULT 0.00,
  status             ENUM('pending','completed','cancelled') NOT NULL DEFAULT 'completed',
  created_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_sales_returns_number (return_number),
  CONSTRAINT fk_sr_sale FOREIGN KEY (original_sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
  CONSTRAINT fk_sr_user FOREIGN KEY (processed_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_sr_sale (original_sale_id),
  INDEX idx_sr_status (status),          -- v1.2 (finding 6.2)
  INDEX idx_sr_processed_by (processed_by)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales_return_line_items (
  id                   BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id            BIGINT UNSIGNED NOT NULL,
  sale_line_item_id    BIGINT UNSIGNED NOT NULL,
  product_id           BIGINT UNSIGNED NOT NULL,
  quantity             DECIMAL(12,3) UNSIGNED NOT NULL,
  condition_type       ENUM('sellable','damaged') NOT NULL,
  reason               VARCHAR(255) NULL,
  CONSTRAINT fk_srli_return FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE,
  CONSTRAINT fk_srli_sli FOREIGN KEY (sale_line_item_id) REFERENCES sale_line_items(id) ON DELETE RESTRICT,
  CONSTRAINT fk_srli_product FOREIGN KEY (product_id) REFERENCES products(id) ON DELETE RESTRICT,
  CONSTRAINT chk_srli_quantity CHECK (quantity > 0),
  INDEX idx_srli_return (return_id),
  INDEX idx_srli_sli (sale_line_item_id)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v1.1: gives returns the same durable, printable, price-change-proof snapshot
-- that `receipts` gives sales (Sec. 11.1 "Generating return receipts").
CREATE TABLE return_receipts (
  id                     BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  return_id              BIGINT UNSIGNED NOT NULL,
  receipt_number         VARCHAR(50) NOT NULL,
  business_snapshot      JSON NOT NULL,
  line_items_snapshot    JSON NOT NULL,
  totals_snapshot        JSON NOT NULL,
  print_status           ENUM('not_printed','printed') NOT NULL DEFAULT 'not_printed',
  printed_at             DATETIME NULL,
  created_at             TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  UNIQUE KEY uq_return_receipts_return (return_id),
  UNIQUE KEY uq_return_receipts_number (receipt_number),
  CONSTRAINT fk_return_receipts_return FOREIGN KEY (return_id) REFERENCES sales_returns(id) ON DELETE CASCADE
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 8: CUSTOMER CREDIT LEDGER (R15) — configurable module
-- ============================================================================

CREATE TABLE credit_transactions (
  id             BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  customer_id    BIGINT UNSIGNED NOT NULL,
  sale_id        BIGINT UNSIGNED NULL,          -- set when type = credit_sale
  type           ENUM('credit_sale','payment') NOT NULL,
  amount         DECIMAL(12,2) UNSIGNED NOT NULL,
  balance_after  DECIMAL(12,2) UNSIGNED NOT NULL,
  created_by     BIGINT UNSIGNED NOT NULL,
  created_at     TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_ct_customer FOREIGN KEY (customer_id) REFERENCES customers(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ct_sale FOREIGN KEY (sale_id) REFERENCES sales(id) ON DELETE RESTRICT,
  CONSTRAINT fk_ct_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE RESTRICT,
  INDEX idx_ct_customer_date (customer_id, created_at),
  INDEX idx_ct_sale (sale_id)   -- v1.2 (finding 6.1)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 9: NOTIFICATIONS (R19)
-- ============================================================================

CREATE TABLE system_notifications (
  id               BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category         ENUM('inventory','sales','customer','user_system') NOT NULL,
  message          VARCHAR(500) NOT NULL,
  target_user_id   BIGINT UNSIGNED NULL,
  target_role_id   BIGINT UNSIGNED NULL,
  related_table    VARCHAR(50) NULL,
  related_id       BIGINT UNSIGNED NULL,
  is_read          BOOLEAN NOT NULL DEFAULT FALSE,
  created_at       TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_notif_user FOREIGN KEY (target_user_id) REFERENCES users(id) ON DELETE CASCADE,
  CONSTRAINT fk_notif_role FOREIGN KEY (target_role_id) REFERENCES roles(id) ON DELETE CASCADE,
  CONSTRAINT chk_notif_has_target CHECK (target_user_id IS NOT NULL OR target_role_id IS NOT NULL),  -- v1.2 (finding 2.2)
  INDEX idx_notif_user_read (target_user_id, is_read),
  INDEX idx_notif_role (target_role_id),
  INDEX idx_notif_category (category),
  INDEX idx_notif_related (related_table, related_id)   -- v1.2 (finding 6.3)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 10: AUDIT LOG (R20) — core, mandatory, immutable, polymorphic
-- ============================================================================

CREATE TABLE audit_logs (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  user_id         BIGINT UNSIGNED NULL,          -- nullable: system-generated events have no human actor
  action          VARCHAR(100) NOT NULL,         -- e.g. 'create', 'update', 'delete', 'login', 'void_sale'
  module          VARCHAR(100) NOT NULL,         -- e.g. 'products', 'sales', 'users', 'settings'
  record_type     VARCHAR(100) NOT NULL,         -- entity name, e.g. 'Product', 'Sale'
  record_id       BIGINT UNSIGNED NULL,
  previous_value  JSON NULL,
  new_value       JSON NULL,
  ip_address      VARCHAR(45) NULL,
  device_info     VARCHAR(255) NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  CONSTRAINT fk_audit_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE SET NULL,
  INDEX idx_audit_module_record (module, record_type, record_id),
  INDEX idx_audit_user_date (user_id, created_at),
  INDEX idx_audit_date (created_at)
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 11: CENTRALIZED SETTINGS (R21) — grouped, strongly-typed singletons
-- ============================================================================

CREATE TABLE general_settings (
  id                 TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  business_name      VARCHAR(150) NOT NULL,
  business_logo_url  VARCHAR(255) NULL,
  contact_phone      VARCHAR(30)  NULL,
  contact_email      VARCHAR(150) NULL,
  address            VARCHAR(255) NULL,
  currency_code      CHAR(3) NOT NULL DEFAULT 'USD',
  date_format        VARCHAR(20) NOT NULL DEFAULT 'YYYY-MM-DD',
  time_format        VARCHAR(20) NOT NULL DEFAULT '24h',
  tax_enabled        BOOLEAN NOT NULL DEFAULT FALSE,
  tax_rate           DECIMAL(5,2) UNSIGNED NOT NULL DEFAULT 0.00,
  updated_by         BIGINT UNSIGNED NULL,
  updated_at         TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_general_settings_singleton CHECK (id = 1),
  CONSTRAINT fk_general_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE store_settings (
  id                       TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  opening_time             TIME NULL,
  closing_time             TIME NULL,
  receipt_business_info    TEXT NULL,
  updated_by               BIGINT UNSIGNED NULL,
  updated_at               TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_store_settings_singleton CHECK (id = 1),
  CONSTRAINT fk_store_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE sales_settings (
  id                          TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  default_payment_method_id  BIGINT UNSIGNED NULL,
  max_discount_percentage    DECIMAL(5,2) UNSIGNED NOT NULL DEFAULT 100.00,
  allow_negative_stock_sale  BOOLEAN NOT NULL DEFAULT FALSE,
  updated_by                 BIGINT UNSIGNED NULL,
  updated_at                 TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_sales_settings_singleton CHECK (id = 1),
  CONSTRAINT fk_sales_settings_method FOREIGN KEY (default_payment_method_id) REFERENCES payment_methods(id) ON DELETE SET NULL,
  CONSTRAINT fk_sales_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- v1.1: closes the "Security Settings" gap referenced in the SRS's own
-- settings navigation (Sec. 20.15) but never given a field list in the body text.
CREATE TABLE security_settings (
  id                                TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  session_timeout_minutes           INT UNSIGNED NOT NULL DEFAULT 60,
  max_failed_login_attempts         INT UNSIGNED NOT NULL DEFAULT 5,
  lockout_duration_minutes          INT UNSIGNED NOT NULL DEFAULT 15,
  password_min_length               TINYINT UNSIGNED NOT NULL DEFAULT 8,
  password_reset_token_ttl_minutes  INT UNSIGNED NOT NULL DEFAULT 30,
  updated_by                        BIGINT UNSIGNED NULL,
  updated_at                        TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_security_settings_singleton CHECK (id = 1),
  CONSTRAINT fk_security_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE inventory_settings (
  id                            TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  low_stock_default_threshold  DECIMAL(12,3) UNSIGNED NOT NULL DEFAULT 10.000,
  expiry_alert_days_1          INT UNSIGNED NOT NULL DEFAULT 7,
  expiry_alert_days_2          INT UNSIGNED NOT NULL DEFAULT 30,
  expiry_alert_days_3          INT UNSIGNED NOT NULL DEFAULT 60,
  updated_by                   BIGINT UNSIGNED NULL,
  updated_at                   TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_inventory_settings_singleton CHECK (id = 1),
  CONSTRAINT fk_inventory_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- Multiple rows: one per configurable module (Loyalty intentionally excluded)
CREATE TABLE module_settings (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  module_name   ENUM('purchase_management','return_management','customer_credit','notifications') NOT NULL,
  is_enabled    BOOLEAN NOT NULL DEFAULT TRUE,
  updated_by    BIGINT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_module_settings_name (module_name),
  CONSTRAINT fk_module_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE hardware_settings (
  id                        TINYINT UNSIGNED PRIMARY KEY DEFAULT 1,
  barcode_scanner_enabled  BOOLEAN NOT NULL DEFAULT TRUE,
  auto_print_receipt       BOOLEAN NOT NULL DEFAULT FALSE,
  default_printer_name     VARCHAR(150) NULL,
  paper_size                ENUM('58mm','80mm') NOT NULL DEFAULT '80mm',
  updated_by                BIGINT UNSIGNED NULL,
  updated_at                TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  CONSTRAINT chk_hardware_settings_singleton CHECK (id = 1),
  CONSTRAINT fk_hardware_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

CREATE TABLE notification_settings (
  id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  category      ENUM('inventory','sales','customer','user_system') NOT NULL,
  is_enabled    BOOLEAN NOT NULL DEFAULT TRUE,
  updated_by    BIGINT UNSIGNED NULL,
  updated_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
  UNIQUE KEY uq_notification_settings_category (category),
  CONSTRAINT fk_notification_settings_user FOREIGN KEY (updated_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 12: BACKUP METADATA (R23)
-- ============================================================================

CREATE TABLE backup_records (
  id              BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
  scope           VARCHAR(100) NOT NULL DEFAULT 'full',
  status          ENUM('pending','completed','failed') NOT NULL DEFAULT 'pending',
  file_reference  VARCHAR(255) NULL,
  created_by      BIGINT UNSIGNED NULL,
  created_at      TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
  completed_at    DATETIME NULL,
  CONSTRAINT fk_backup_user FOREIGN KEY (created_by) REFERENCES users(id) ON DELETE SET NULL
) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci;

-- ============================================================================
-- SECTION 13: IMMUTABILITY ENFORCEMENT (DB-level defense in depth)
-- Business rule: audit_logs and inventory_movements are append-only ledgers.
-- The primary control should be restricting UPDATE/DELETE grants for the
-- application's DB user on these two tables; these triggers are a backstop.
-- ============================================================================

DELIMITER $$

CREATE TRIGGER trg_audit_logs_no_update
BEFORE UPDATE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs records are immutable and cannot be updated';
END$$

CREATE TRIGGER trg_audit_logs_no_delete
BEFORE DELETE ON audit_logs
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'audit_logs records cannot be deleted';
END$$

CREATE TRIGGER trg_inventory_movements_no_update
BEFORE UPDATE ON inventory_movements
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory_movements is an append-only ledger and cannot be updated';
END$$

CREATE TRIGGER trg_inventory_movements_no_delete
BEFORE DELETE ON inventory_movements
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'inventory_movements records cannot be deleted';
END$$

DELIMITER ;

-- ============================================================================
-- SECTION 13b: BUSINESS-RULE ENFORCEMENT TRIGGERS (v1.1)
-- These close specific gaps flagged in the traceability matrix. Each is a
-- narrow, single-purpose trigger — deliberately not a substitute for the
-- application-level transaction logic in Section 4 of the design doc, but a
-- second line of defense for rules that are cheap and safe to check at the
-- row level.
-- ============================================================================

DELIMITER $$

-- Sec. 10.2: "Credit sales require customer selection." Enforced at the
-- payments table (both INSERT and UPDATE) rather than on `sales`, because
-- the payment method — not the sale itself — is what makes a transaction a
-- credit transaction.
CREATE TRIGGER trg_payments_credit_requires_customer
BEFORE INSERT ON payments
FOR EACH ROW
BEGIN
  DECLARE v_code VARCHAR(30);
  DECLARE v_customer_id BIGINT UNSIGNED;
  SELECT code INTO v_code FROM payment_methods WHERE id = NEW.payment_method_id;
  IF v_code = 'credit' THEN
    SELECT customer_id INTO v_customer_id FROM sales WHERE id = NEW.sale_id;
    IF v_customer_id IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Credit sales require a customer to be selected';
    END IF;
  END IF;
END$$

CREATE TRIGGER trg_payments_credit_requires_customer_upd
BEFORE UPDATE ON payments
FOR EACH ROW
BEGIN
  DECLARE v_code VARCHAR(30);
  DECLARE v_customer_id BIGINT UNSIGNED;
  SELECT code INTO v_code FROM payment_methods WHERE id = NEW.payment_method_id;
  IF v_code = 'credit' THEN
    SELECT customer_id INTO v_customer_id FROM sales WHERE id = NEW.sale_id;
    IF v_customer_id IS NULL THEN
      SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Credit sales require a customer to be selected';
    END IF;
  END IF;
END$$

-- Sec. 6.1/6.2: keeps `batches.status` consistent automatically instead of
-- relying on every application code path to remember to flip it. Handles
-- both directions: a sale that drains a batch to zero, and a later sellable
-- return that restores quantity to a previously depleted batch.
CREATE TRIGGER trg_batches_auto_depleted
BEFORE UPDATE ON batches
FOR EACH ROW
BEGIN
  IF NEW.qty_remaining = 0 AND OLD.status = 'active' THEN
    SET NEW.status = 'depleted';
  ELSEIF NEW.qty_remaining > 0 AND OLD.status = 'depleted' THEN
    SET NEW.status = 'active';
  END IF;
END$$

-- Sec. 14 "Inventory Notifications: Low Stock Products". Fires only on an
-- actual quantity change, and suppresses duplicate alerts for the same
-- product within a rolling 24-hour window so a busy till doesn't spam the
-- notification feed with one row per sale.
-- v1.2 fix: now checks module_settings('notifications') and
-- notification_settings('inventory') before inserting — v1.1 shipped this
-- trigger without respecting the configurable-notifications toggle, which
-- contradicted the SRS requirement it was meant to serve.
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
          INSERT INTO system_notifications (category, message, related_table, related_id)
          VALUES ('inventory', CONCAT('Low stock: "', v_name, '" is at or below its minimum stock level (', v_total, ' remaining).'), 'products', NEW.product_id);
        END IF;
      END IF;
    END IF;
  END IF;
END$$

-- Sec. 14 "Customer Notifications: Credit Limit Reached". Fires only on the
-- transition into breach, not on every update, to avoid repeat noise.
-- v1.2 fix: same module/category-toggle check as above.
CREATE TRIGGER trg_customers_credit_limit_notify
AFTER UPDATE ON customers
FOR EACH ROW
BEGIN
  DECLARE v_module_enabled BOOLEAN;
  DECLARE v_category_enabled BOOLEAN;
  IF NEW.credit_enabled = TRUE
     AND NEW.outstanding_balance >= NEW.credit_limit
     AND OLD.outstanding_balance < OLD.credit_limit THEN
    SELECT COALESCE(is_enabled, FALSE) INTO v_module_enabled FROM module_settings WHERE module_name = 'notifications';
    SELECT COALESCE(is_enabled, FALSE) INTO v_category_enabled FROM notification_settings WHERE category = 'customer';
    IF v_module_enabled = TRUE AND v_category_enabled = TRUE THEN
      INSERT INTO system_notifications (category, message, related_table, related_id)
      VALUES ('customer', CONCAT('Customer "', NEW.name, '" has reached their credit limit.'), 'customers', NEW.id);
    END IF;
  END IF;
END$$

DELIMITER ;

-- ============================================================================
-- SECTION 13c: SCHEDULED EVENT — DAILY EXPIRY SWEEP (v1.1)
-- Sec. 6.3 "Expiry Management": marks batches expired once past their date,
-- and raises expiry notifications at the configured alert windows
-- (inventory_settings.expiry_alert_days_1/2/3). Requires the MySQL event
-- scheduler to be enabled (see header comment at the top of this file).
-- ============================================================================

DELIMITER $$

CREATE EVENT IF NOT EXISTS ev_daily_expiry_sweep
ON SCHEDULE EVERY 1 DAY STARTS (CURRENT_DATE + INTERVAL 1 DAY + INTERVAL 1 HOUR)
DO
BEGIN
  -- 1. Flip any batch that is now past its expiry date to 'expired'.
  --    (This UPDATE is exempt from the "no negative stock" business logic —
  --    it only changes status, never qty_remaining.)
  UPDATE batches
  SET status = 'expired'
  WHERE status = 'active'
    AND expiry_date IS NOT NULL
    AND expiry_date < CURDATE()
    AND qty_remaining > 0;

  -- 2. Raise "expiring soon" notifications at each configured alert window,
  --    skipping any batch that already got a same-day notification.
  --    v1.2: also respects the notifications module/category toggle — the
  --    expired-status update above always runs (it's inventory state, not a
  --    notification), but the notification itself is gated like every other
  --    notification-generating trigger in this schema.
  INSERT INTO system_notifications (category, message, related_table, related_id)
  SELECT
    'inventory',
    CONCAT('Batch of "', p.name, '" expires on ', b.expiry_date, ' (', DATEDIFF(b.expiry_date, CURDATE()), ' day(s)).'),
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
END$$

DELIMITER ;

-- ============================================================================
-- SECTION 13d: v1.2 CRITICAL & REQUIREMENT-GAP FIXES (see pos_schema_audit.md)
-- ============================================================================

DELIMITER $$

-- Finding 5.1 (Critical): `sales` had no delete protection at all, unlike
-- `audit_logs`/`inventory_movements`. Voiding/refunding are the only
-- legitimate ways to reverse a sale — physical deletion is never correct.
CREATE TRIGGER trg_sales_no_delete
BEFORE DELETE ON sales
FOR EACH ROW
BEGIN
  SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Sales records cannot be deleted. Use void or a return instead.';
END$$

-- Finding 5.5 (Requirement Gap): a draft PO that was never sent to a
-- supplier is reasonably deletable; anything beyond 'draft' represents real
-- purchasing history and batch provenance and must be permanent.
CREATE TRIGGER trg_purchase_orders_restrict_delete
BEFORE DELETE ON purchase_orders
FOR EACH ROW
BEGIN
  IF OLD.status <> 'draft' THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'Only draft purchase orders can be deleted; ordered/received/cancelled POs are permanent history.';
  END IF;
END$$

-- Finding 4.2 (Critical): voiding a sale had columns to record the void but
-- nothing reversed the inventory deduction or the credit-balance charge.
-- This trigger makes both reversals automatic and atomic with the void
-- itself, instead of relying on application code to remember every step.
CREATE TRIGGER trg_sales_void_reverses_inventory
AFTER UPDATE ON sales
FOR EACH ROW
main_block: BEGIN
  DECLARE done INT DEFAULT FALSE;
  DECLARE v_batch_id BIGINT UNSIGNED;
  DECLARE v_qty DECIMAL(12,3);
  DECLARE v_product_id BIGINT UNSIGNED;
  DECLARE v_prev_batch_qty DECIMAL(12,3);
  DECLARE v_is_credit INT DEFAULT 0;
  DECLARE v_new_balance DECIMAL(12,2);
  DECLARE cur CURSOR FOR
    SELECT slib.batch_id, slib.quantity_deducted, sli.product_id
    FROM sale_line_items sli
    JOIN sale_line_item_batches slib ON slib.sale_line_item_id = sli.id
    WHERE sli.sale_id = NEW.id;
  DECLARE CONTINUE HANDLER FOR NOT FOUND SET done = TRUE;

  IF NOT (NEW.status = 'voided' AND OLD.status <> 'voided') THEN
    LEAVE main_block;
  END IF;

  -- 1. Reverse the inventory deduction for every batch this sale drew from.
  OPEN cur;
  read_loop: LOOP
    FETCH cur INTO v_batch_id, v_qty, v_product_id;
    IF done THEN
      LEAVE read_loop;
    END IF;
    SELECT qty_remaining INTO v_prev_batch_qty FROM batches WHERE id = v_batch_id FOR UPDATE;
    UPDATE batches SET qty_remaining = qty_remaining + v_qty WHERE id = v_batch_id;
    INSERT INTO inventory_movements
      (product_id, batch_id, movement_type, quantity, previous_qty, new_qty, reference_table, reference_id, reason, user_id)
    VALUES
      (v_product_id, v_batch_id, 'adjustment', v_qty, v_prev_batch_qty, v_prev_batch_qty + v_qty,
       'sales', NEW.id, CONCAT('Sale voided: ', COALESCE(NEW.void_reason, 'no reason given')),
       COALESCE(NEW.voided_by, NEW.cashier_id));
  END LOOP;
  CLOSE cur;

  -- 2. If this was a credit sale, reverse the charge against the customer's balance.
  SELECT COUNT(*) INTO v_is_credit
  FROM payments p
  JOIN payment_methods pm ON pm.id = p.payment_method_id
  WHERE p.sale_id = NEW.id AND pm.code = 'credit';

  IF v_is_credit > 0 AND NEW.customer_id IS NOT NULL THEN
    UPDATE customers
    SET outstanding_balance = GREATEST(outstanding_balance - NEW.total_amount, 0)
    WHERE id = NEW.customer_id;

    SELECT outstanding_balance INTO v_new_balance FROM customers WHERE id = NEW.customer_id;

    INSERT INTO credit_transactions (customer_id, sale_id, type, amount, balance_after, created_by)
    VALUES (NEW.customer_id, NEW.id, 'payment', NEW.total_amount, v_new_balance, COALESCE(NEW.voided_by, NEW.cashier_id));
  END IF;
END$$

-- Finding 1.4 (Requirement Gap): `sales.status` and `sales_returns` were two
-- independent sources of truth for "this sale was refunded," with nothing
-- keeping them in sync. `sales_returns.status` defaults to 'completed' on
-- insert, so both an INSERT and an UPDATE path need to sync it.
CREATE TRIGGER trg_sales_returns_sync_status_ins
AFTER INSERT ON sales_returns
FOR EACH ROW
BEGIN
  IF NEW.status = 'completed' THEN
    UPDATE sales SET status = 'refunded' WHERE id = NEW.original_sale_id AND status = 'completed';
  END IF;
END$$

CREATE TRIGGER trg_sales_returns_sync_status_upd
AFTER UPDATE ON sales_returns
FOR EACH ROW
BEGIN
  IF NEW.status = 'completed' AND OLD.status <> 'completed' THEN
    UPDATE sales SET status = 'refunded' WHERE id = NEW.original_sale_id AND status = 'completed';
  END IF;
END$$

-- Finding 2.4 (Improvement): `payment_methods.code` is read by literal
-- string value inside `trg_payments_credit_requires_customer`. Locking the
-- code column after creation stops an innocent settings-UI rename from
-- silently disabling that business rule with no error anywhere.
CREATE TRIGGER trg_payment_methods_code_immutable
BEFORE UPDATE ON payment_methods
FOR EACH ROW
BEGIN
  IF NEW.code <> OLD.code THEN
    SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'payment_methods.code is a stable system identifier and cannot be changed after creation. Edit the display name instead.';
  END IF;
END$$

DELIMITER ;

-- ============================================================================
-- SECTION 13e: v1.2 SETTINGS AUDIT TRIGGERS (findings 4.3 / 11.4)
-- Every other business-rule change in this schema writes to audit_logs
-- automatically; settings changes previously relied entirely on the
-- application remembering to do it, despite Sec. 15.1 explicitly listing
-- "Settings Changes" as a mandatory audit activity.
-- ============================================================================

DELIMITER $$

CREATE TRIGGER trg_audit_general_settings
AFTER UPDATE ON general_settings
FOR EACH ROW
BEGIN
  INSERT INTO audit_logs (user_id, action, module, record_type, record_id, previous_value, new_value)
  VALUES (NEW.updated_by, 'update', 'settings', 'general_settings', NEW.id,
    JSON_OBJECT('business_name', OLD.business_name, 'currency_code', OLD.currency_code, 'tax_enabled', OLD.tax_enabled, 'tax_rate', OLD.tax_rate, 'date_format', OLD.date_format, 'time_format', OLD.time_format),
    JSON_OBJECT('business_name', NEW.business_name, 'currency_code', NEW.currency_code, 'tax_enabled', NEW.tax_enabled, 'tax_rate', NEW.tax_rate, 'date_format', NEW.date_format, 'time_format', NEW.time_format));
END$$

CREATE TRIGGER trg_audit_store_settings
AFTER UPDATE ON store_settings
FOR EACH ROW
BEGIN
  INSERT INTO audit_logs (user_id, action, module, record_type, record_id, previous_value, new_value)
  VALUES (NEW.updated_by, 'update', 'settings', 'store_settings', NEW.id,
    JSON_OBJECT('opening_time', OLD.opening_time, 'closing_time', OLD.closing_time),
    JSON_OBJECT('opening_time', NEW.opening_time, 'closing_time', NEW.closing_time));
END$$

CREATE TRIGGER trg_audit_sales_settings
AFTER UPDATE ON sales_settings
FOR EACH ROW
BEGIN
  INSERT INTO audit_logs (user_id, action, module, record_type, record_id, previous_value, new_value)
  VALUES (NEW.updated_by, 'update', 'settings', 'sales_settings', NEW.id,
    JSON_OBJECT('default_payment_method_id', OLD.default_payment_method_id, 'max_discount_percentage', OLD.max_discount_percentage, 'allow_negative_stock_sale', OLD.allow_negative_stock_sale),
    JSON_OBJECT('default_payment_method_id', NEW.default_payment_method_id, 'max_discount_percentage', NEW.max_discount_percentage, 'allow_negative_stock_sale', NEW.allow_negative_stock_sale));
END$$

CREATE TRIGGER trg_audit_inventory_settings
AFTER UPDATE ON inventory_settings
FOR EACH ROW
BEGIN
  INSERT INTO audit_logs (user_id, action, module, record_type, record_id, previous_value, new_value)
  VALUES (NEW.updated_by, 'update', 'settings', 'inventory_settings', NEW.id,
    JSON_OBJECT('low_stock_default_threshold', OLD.low_stock_default_threshold, 'expiry_alert_days_1', OLD.expiry_alert_days_1, 'expiry_alert_days_2', OLD.expiry_alert_days_2, 'expiry_alert_days_3', OLD.expiry_alert_days_3),
    JSON_OBJECT('low_stock_default_threshold', NEW.low_stock_default_threshold, 'expiry_alert_days_1', NEW.expiry_alert_days_1, 'expiry_alert_days_2', NEW.expiry_alert_days_2, 'expiry_alert_days_3', NEW.expiry_alert_days_3));
END$$

CREATE TRIGGER trg_audit_hardware_settings
AFTER UPDATE ON hardware_settings
FOR EACH ROW
BEGIN
  INSERT INTO audit_logs (user_id, action, module, record_type, record_id, previous_value, new_value)
  VALUES (NEW.updated_by, 'update', 'settings', 'hardware_settings', NEW.id,
    JSON_OBJECT('barcode_scanner_enabled', OLD.barcode_scanner_enabled, 'auto_print_receipt', OLD.auto_print_receipt, 'default_printer_name', OLD.default_printer_name, 'paper_size', OLD.paper_size),
    JSON_OBJECT('barcode_scanner_enabled', NEW.barcode_scanner_enabled, 'auto_print_receipt', NEW.auto_print_receipt, 'default_printer_name', NEW.default_printer_name, 'paper_size', NEW.paper_size));
END$$

CREATE TRIGGER trg_audit_security_settings
AFTER UPDATE ON security_settings
FOR EACH ROW
BEGIN
  INSERT INTO audit_logs (user_id, action, module, record_type, record_id, previous_value, new_value)
  VALUES (NEW.updated_by, 'update', 'settings', 'security_settings', NEW.id,
    JSON_OBJECT('session_timeout_minutes', OLD.session_timeout_minutes, 'max_failed_login_attempts', OLD.max_failed_login_attempts, 'lockout_duration_minutes', OLD.lockout_duration_minutes, 'password_min_length', OLD.password_min_length),
    JSON_OBJECT('session_timeout_minutes', NEW.session_timeout_minutes, 'max_failed_login_attempts', NEW.max_failed_login_attempts, 'lockout_duration_minutes', NEW.lockout_duration_minutes, 'password_min_length', NEW.password_min_length));
END$$

CREATE TRIGGER trg_audit_module_settings
AFTER UPDATE ON module_settings
FOR EACH ROW
BEGIN
  IF NEW.is_enabled <> OLD.is_enabled THEN
    INSERT INTO audit_logs (user_id, action, module, record_type, record_id, previous_value, new_value)
    VALUES (NEW.updated_by, 'update', 'settings', 'module_settings', NEW.id,
      JSON_OBJECT('module_name', OLD.module_name, 'is_enabled', OLD.is_enabled),
      JSON_OBJECT('module_name', NEW.module_name, 'is_enabled', NEW.is_enabled));
  END IF;
END$$

CREATE TRIGGER trg_audit_notification_settings
AFTER UPDATE ON notification_settings
FOR EACH ROW
BEGIN
  IF NEW.is_enabled <> OLD.is_enabled THEN
    INSERT INTO audit_logs (user_id, action, module, record_type, record_id, previous_value, new_value)
    VALUES (NEW.updated_by, 'update', 'settings', 'notification_settings', NEW.id,
      JSON_OBJECT('category', OLD.category, 'is_enabled', OLD.is_enabled),
      JSON_OBJECT('category', NEW.category, 'is_enabled', NEW.is_enabled));
  END IF;
END$$

DELIMITER ;

-- ============================================================================
-- SECTION 14: REPORTING-SUPPORT VIEWS (read-only convenience layer)
-- ============================================================================

-- Current stock on hand per product, derived from active batches.
CREATE VIEW v_current_stock AS
SELECT
  p.id                          AS product_id,
  p.name                        AS product_name,
  p.min_stock_level             AS min_stock_level,
  COALESCE(SUM(b.qty_remaining), 0) AS qty_on_hand,
  CASE WHEN COALESCE(SUM(b.qty_remaining), 0) <= p.min_stock_level THEN 1 ELSE 0 END AS is_low_stock
FROM products p
LEFT JOIN batches b
  ON b.product_id = p.id AND b.status = 'active'
WHERE p.status = 'active'
GROUP BY p.id, p.name, p.min_stock_level;

-- Batches with a valid (non-null) expiry date, soonest first — feeds
-- "Products Expiring Soon" / "Expired Products" reports and notifications.
CREATE VIEW v_batch_expiry AS
SELECT
  b.id            AS batch_id,
  b.product_id    AS product_id,
  p.name          AS product_name,
  b.qty_remaining AS qty_remaining,
  b.expiry_date   AS expiry_date,
  DATEDIFF(b.expiry_date, CURDATE()) AS days_to_expiry
FROM batches b
JOIN products p ON p.id = b.product_id
WHERE b.expiry_date IS NOT NULL
  AND b.qty_remaining > 0
  AND b.status = 'active';

-- v1.1: closes the "no pre-built report/dashboard objects" gap for the
-- highest-value named reports (Sec. 12, 13). Each view pulls only from
-- already-indexed columns, so they stay cheap even as data grows; if any of
-- these get hit heavily in production, materialize them into a
-- scheduled-refresh summary table instead of querying live.

-- Sales Reports: Daily Sales Report (Sec. 13.1)
CREATE VIEW v_daily_sales_summary AS
SELECT
  DATE(sale_date)        AS sale_day,
  COUNT(*)                AS transaction_count,
  SUM(subtotal)            AS gross_subtotal,
  SUM(discount_amount)     AS total_discounts,
  SUM(total_amount)        AS total_revenue
FROM sales
WHERE status = 'completed'
GROUP BY DATE(sale_date);

-- Sales Reports: Sales by Cashier Report (Sec. 13.1)
CREATE VIEW v_sales_by_cashier AS
SELECT
  s.cashier_id,
  u.name              AS cashier_name,
  DATE(s.sale_date)    AS sale_day,
  COUNT(*)              AS transaction_count,
  SUM(s.total_amount)   AS total_revenue
FROM sales s
JOIN users u ON u.id = s.cashier_id
WHERE s.status = 'completed'
GROUP BY s.cashier_id, u.name, DATE(s.sale_date);

-- Sales Reports: Sales by Payment Method Report (Sec. 13.1)
CREATE VIEW v_sales_by_payment_method AS
SELECT
  pm.id               AS payment_method_id,
  pm.name             AS payment_method_name,
  DATE(s.sale_date)   AS sale_day,
  COUNT(*)             AS transaction_count,
  SUM(p.amount)        AS total_amount
FROM payments p
JOIN payment_methods pm ON pm.id = p.payment_method_id
JOIN sales s ON s.id = p.sale_id
WHERE s.status = 'completed'
GROUP BY pm.id, pm.name, DATE(s.sale_date);

-- Sales Reports: Discount Report (Sec. 13.1) — header- and line-level discounts
CREATE VIEW v_discount_report AS
SELECT
  s.id                    AS sale_id,
  s.receipt_number,
  s.sale_date,
  s.discount_type         AS header_discount_type,
  s.discount_amount       AS header_discount_amount,
  s.discount_reason       AS header_discount_reason,
  du.name                 AS header_discount_applied_by,
  COALESCE(SUM(sli.line_discount_amount), 0) AS total_line_discounts
FROM sales s
LEFT JOIN users du ON du.id = s.discount_applied_by
LEFT JOIN sale_line_items sli ON sli.sale_id = s.id
WHERE s.discount_amount > 0
   OR EXISTS (SELECT 1 FROM sale_line_items x WHERE x.sale_id = s.id AND x.line_discount_amount > 0)
GROUP BY s.id, s.receipt_number, s.sale_date, s.discount_type, s.discount_amount, s.discount_reason, du.name;

-- Sales Reports: Refund Report (Sec. 13.1)
CREATE VIEW v_refund_report AS
SELECT
  sr.id               AS return_id,
  sr.return_number,
  sr.original_sale_id,
  s.receipt_number    AS original_receipt_number,
  sr.reason,
  sr.refund_amount,
  sr.status,
  sr.created_at,
  u.name              AS processed_by
FROM sales_returns sr
JOIN sales s ON s.id = sr.original_sale_id
JOIN users u ON u.id = sr.processed_by;

-- Inventory Reports: Low Stock Report / Out of Stock Report (Sec. 13.2)
CREATE VIEW v_low_stock AS
SELECT * FROM v_current_stock WHERE is_low_stock = 1 AND qty_on_hand > 0;

CREATE VIEW v_out_of_stock AS
SELECT * FROM v_current_stock WHERE qty_on_hand <= 0;

-- Inventory Reports: Inventory Valuation Report (Sec. 13.2) + Dashboard
-- Inventory KPIs (Sec. 12.1: value at cost, value at selling, gross profit)
CREATE VIEW v_inventory_valuation AS
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
GROUP BY p.id, p.name;

-- Product Reports: Product Performance / Fast-Moving / Slow-Moving /
-- Top-Selling / Least-Selling (Sec. 12.1, 13.3) — all derivable by sorting
-- this one view by total_qty_sold or total_revenue, ASC or DESC.
CREATE VIEW v_product_sales_summary AS
SELECT
  sli.product_id,
  p.name                        AS product_name,
  SUM(sli.quantity)              AS total_qty_sold,
  SUM(sli.subtotal)              AS total_revenue,
  COUNT(DISTINCT sli.sale_id)    AS transaction_count,
  MAX(s.sale_date)               AS last_sold_at
FROM sale_line_items sli
JOIN sales s ON s.id = sli.sale_id
JOIN products p ON p.id = sli.product_id
WHERE s.status = 'completed'
GROUP BY sli.product_id, p.name;

-- Financial Reports: Credit Sales Report / Outstanding Customer Balances (Sec. 13.4)
CREATE VIEW v_credit_outstanding_balances AS
SELECT
  id                              AS customer_id,
  name                             AS customer_name,
  credit_limit,
  outstanding_balance,
  (credit_limit - outstanding_balance) AS available_credit
FROM customers
WHERE credit_enabled = TRUE
  AND outstanding_balance > 0;

-- v1.1: data-integrity monitoring for the cross-table rules that MySQL
-- CHECK constraints structurally cannot express (Sec. 8.2 "empty sales
-- cannot be completed"; a completed sale should always have a payment).
-- Intended for a scheduled ops job to poll, not for blocking writes.
CREATE VIEW v_integrity_sales_without_lines AS
SELECT s.id AS sale_id, s.receipt_number, s.sale_date
FROM sales s
LEFT JOIN sale_line_items sli ON sli.sale_id = s.id
WHERE sli.id IS NULL;

CREATE VIEW v_integrity_completed_sales_without_payment AS
SELECT s.id AS sale_id, s.receipt_number, s.sale_date
FROM sales s
LEFT JOIN payments p ON p.sale_id = s.id
WHERE p.id IS NULL AND s.status = 'completed';

-- v1.2 (finding 2.3): flags any sale line where the batches allocated to it
-- don't sum to the line's own quantity — a cross-table rule CHECK cannot
-- express, same category as the two views above.
CREATE VIEW v_integrity_line_batch_mismatch AS
SELECT
  sli.id AS sale_line_item_id,
  sli.sale_id,
  sli.product_id,
  sli.quantity AS line_quantity,
  COALESCE(SUM(slib.quantity_deducted), 0) AS allocated_quantity
FROM sale_line_items sli
LEFT JOIN sale_line_item_batches slib ON slib.sale_line_item_id = sli.id
GROUP BY sli.id, sli.sale_id, sli.product_id, sli.quantity
HAVING line_quantity <> allocated_quantity;

-- v1.2 (Section 9 denormalization reconciliation): flags any customer whose
-- stored `outstanding_balance` (fast, checked on every credit sale) has
-- drifted from what the `credit_transactions` ledger actually sums to.
-- Intended for the same scheduled ops job that polls the views above.
CREATE VIEW v_integrity_credit_balance_mismatch AS
SELECT
  c.id AS customer_id,
  c.name AS customer_name,
  c.outstanding_balance AS stored_balance,
  COALESCE(SUM(CASE WHEN ct.type = 'credit_sale' THEN ct.amount
                     WHEN ct.type = 'payment' THEN -ct.amount
                     ELSE 0 END), 0) AS ledger_balance
FROM customers c
LEFT JOIN credit_transactions ct ON ct.customer_id = c.id
GROUP BY c.id, c.name, c.outstanding_balance
HAVING stored_balance <> ledger_balance;

-- ============================================================================
-- SECTION 15: SEED DATA (minimal — required singletons and lookups)
-- ============================================================================

-- v1.2 (finding 1.1): without this block, the schema has no way to log in
-- at all on a fresh install — users.role_id is NOT NULL and nothing seeded
-- a role, a permission, or a user. This closes that bootstrap gap.

-- Default roles named in the SRS (Sec. 2). Additional roles (Supervisor,
-- Inventory Manager, Accountant, etc.) are created by the app afterward —
-- the RBAC model is fully dynamic, these two are just the starting point.
INSERT INTO roles (name, description) VALUES
  ('Administrator', 'Full system access'),
  ('Cashier', 'POS checkout and sales processing only');

-- Permission catalog: (module, action) pairs covering every module named in
-- the SRS. `reports` and `audit_logs` are view-only by nature; `settings`
-- has no create/delete since settings rows are singletons/fixed lookups.
INSERT INTO permissions (module, action) VALUES
  ('users','view'),('users','create'),('users','update'),('users','delete'),
  ('roles','view'),('roles','create'),('roles','update'),('roles','delete'),
  ('products','view'),('products','create'),('products','update'),('products','delete'),
  ('categories','view'),('categories','create'),('categories','update'),('categories','delete'),
  ('suppliers','view'),('suppliers','create'),('suppliers','update'),('suppliers','delete'),
  ('inventory','view'),('inventory','create'),('inventory','update'),('inventory','delete'),
  ('purchase_orders','view'),('purchase_orders','create'),('purchase_orders','update'),('purchase_orders','delete'),
  ('sales','view'),('sales','create'),('sales','update'),('sales','delete'),
  ('customers','view'),('customers','create'),('customers','update'),('customers','delete'),
  ('returns','view'),('returns','create'),('returns','update'),('returns','delete'),
  ('reports','view'),
  ('audit_logs','view'),
  ('settings','view'),('settings','update');

-- Administrator gets every permission (Sec. 2: "full access").
INSERT INTO role_permissions (role_id, permission_id)
SELECT (SELECT id FROM roles WHERE name = 'Administrator'), p.id
FROM permissions p;

-- Cashier gets exactly the SRS-listed allowances (Sec. 2) — POS checkout,
-- product search, processing sales, viewing assigned info — and explicitly
-- none of: user management, settings, role management, audit logs, or
-- sensitive reports.
INSERT INTO role_permissions (role_id, permission_id)
SELECT (SELECT id FROM roles WHERE name = 'Cashier'), p.id
FROM permissions p
WHERE (p.module, p.action) IN (
  ('products','view'),
  ('sales','view'), ('sales','create'),
  ('customers','view'), ('customers','create'),
  ('returns','view'), ('returns','create'),
  ('inventory','view')
);

-- Initial admin user. The password_hash below is a deliberately-invalid
-- placeholder (not a real bcrypt/argon2 hash of any password) so this
-- account CANNOT be logged into directly — it will never match any password
-- comparison. Deploy-time setup must run this account through your
-- application's password-reset flow (Laravel's built-in Password::sendResetLink,
-- if using Laravel — see the note on password_reset_tokens near the top of
-- this file) before it can actually be logged into.
-- NOTE: email is a real placeholder, not NULL — Laravel's password broker
-- looks users up BY email to send a reset link, so a NULL email here would
-- make this account unrecoverable via the standard reset flow. Change
-- 'admin@example.com' to a real, monitored address before relying on this.
INSERT INTO users (name, username, email, password_hash, role_id, status) VALUES
  ('System Administrator', 'admin', 'admin@example.com', 'INVALID-PLACEHOLDER-REPLACE-VIA-PASSWORD-RESET-FLOW',
   (SELECT id FROM roles WHERE name = 'Administrator'), 'active');

INSERT INTO general_settings (id, business_name) VALUES (1, 'My Mini Market');
INSERT INTO store_settings (id) VALUES (1);
INSERT INTO sales_settings (id) VALUES (1);
INSERT INTO inventory_settings (id) VALUES (1);
INSERT INTO hardware_settings (id) VALUES (1);
INSERT INTO security_settings (id) VALUES (1);

INSERT INTO module_settings (module_name, is_enabled) VALUES
  ('purchase_management', TRUE),
  ('return_management', TRUE),
  ('customer_credit', TRUE),
  ('notifications', TRUE);

INSERT INTO notification_settings (category, is_enabled) VALUES
  ('inventory', TRUE),
  ('sales', TRUE),
  ('customer', TRUE),
  ('user_system', TRUE);

INSERT INTO payment_methods (name, code, is_enabled) VALUES
  ('Cash', 'cash', TRUE),
  ('Mobile Money', 'mobile_money', TRUE),
  ('Bank Transfer', 'bank_transfer', TRUE),
  ('Credit', 'credit', TRUE);

-- ============================================================================
-- End of schema
-- ============================================================================
