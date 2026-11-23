<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Settings > Security gets a real password policy (length was already
 * there; this adds required character classes, reuse history, and
 * expiry) plus the groundwork for two more security behaviors: a forced
 * password change once a password expires, and flagging a login from a
 * device/location never seen before for that user.
 *
 * password_histories exists so "can't reuse your last N passwords" has
 * something to check against — it stores every hash a user's password
 * has ever been set to, pruned to the most recent 20 per user so it
 * can't grow without bound. Existing users are backfilled with their
 * current hash as history row one, so the reuse check has a real
 * baseline from day one instead of silently allowing one free reuse.
 *
 * users.password_changed_at is what password_expiry_days is measured
 * against. Existing accounts are backfilled to their created_at rather
 * than left NULL — NULL would otherwise mean "never expires" for every
 * account that existed before this migration, which is the opposite of
 * what a newly-configured expiry policy should do to old accounts.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::statement("ALTER TABLE security_settings
            ADD COLUMN password_history_count INT UNSIGNED NOT NULL DEFAULT 5 AFTER password_min_length,
            ADD COLUMN password_expiry_days INT UNSIGNED NOT NULL DEFAULT 90 AFTER password_history_count,
            ADD COLUMN password_require_uppercase BOOLEAN NOT NULL DEFAULT TRUE AFTER password_expiry_days,
            ADD COLUMN password_require_lowercase BOOLEAN NOT NULL DEFAULT TRUE AFTER password_require_uppercase,
            ADD COLUMN password_require_number BOOLEAN NOT NULL DEFAULT TRUE AFTER password_require_lowercase,
            ADD COLUMN password_require_symbol BOOLEAN NOT NULL DEFAULT TRUE AFTER password_require_number");

        DB::statement('ALTER TABLE users ADD COLUMN password_changed_at DATETIME NULL AFTER password_hash');
        DB::statement('UPDATE users SET password_changed_at = created_at WHERE password_changed_at IS NULL');

        DB::statement("CREATE TABLE password_histories (
            id            BIGINT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
            user_id       BIGINT UNSIGNED NOT NULL,
            password_hash VARCHAR(255) NOT NULL,
            created_at    TIMESTAMP NOT NULL DEFAULT CURRENT_TIMESTAMP,
            CONSTRAINT fk_password_histories_user FOREIGN KEY (user_id) REFERENCES users(id) ON DELETE CASCADE,
            INDEX idx_password_histories_user (user_id, id)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");

        DB::statement('INSERT INTO password_histories (user_id, password_hash, created_at)
            SELECT id, password_hash, NOW() FROM users');

        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_security_settings');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_audit_security_settings
            AFTER UPDATE ON security_settings
            FOR EACH ROW
            BEGIN
              INSERT INTO audit_logs (user_id, action, module, record_type, record_id, previous_value, new_value)
              VALUES (NEW.updated_by, 'update', 'settings', 'security_settings', NEW.id,
                JSON_OBJECT('session_timeout_minutes', OLD.session_timeout_minutes, 'max_failed_login_attempts', OLD.max_failed_login_attempts, 'lockout_duration_minutes', OLD.lockout_duration_minutes, 'password_min_length', OLD.password_min_length, 'password_history_count', OLD.password_history_count, 'password_expiry_days', OLD.password_expiry_days, 'password_require_uppercase', OLD.password_require_uppercase, 'password_require_lowercase', OLD.password_require_lowercase, 'password_require_number', OLD.password_require_number, 'password_require_symbol', OLD.password_require_symbol),
                JSON_OBJECT('session_timeout_minutes', NEW.session_timeout_minutes, 'max_failed_login_attempts', NEW.max_failed_login_attempts, 'lockout_duration_minutes', NEW.lockout_duration_minutes, 'password_min_length', NEW.password_min_length, 'password_history_count', NEW.password_history_count, 'password_expiry_days', NEW.password_expiry_days, 'password_require_uppercase', NEW.password_require_uppercase, 'password_require_lowercase', NEW.password_require_lowercase, 'password_require_number', NEW.password_require_number, 'password_require_symbol', NEW.password_require_symbol));
            END
        SQL);
    }

    public function down(): void
    {
        DB::unprepared('DROP TRIGGER IF EXISTS trg_audit_security_settings');
        DB::unprepared(<<<'SQL'
            CREATE TRIGGER trg_audit_security_settings
            AFTER UPDATE ON security_settings
            FOR EACH ROW
            BEGIN
              INSERT INTO audit_logs (user_id, action, module, record_type, record_id, previous_value, new_value)
              VALUES (NEW.updated_by, 'update', 'settings', 'security_settings', NEW.id,
                JSON_OBJECT('session_timeout_minutes', OLD.session_timeout_minutes, 'max_failed_login_attempts', OLD.max_failed_login_attempts, 'lockout_duration_minutes', OLD.lockout_duration_minutes, 'password_min_length', OLD.password_min_length),
                JSON_OBJECT('session_timeout_minutes', NEW.session_timeout_minutes, 'max_failed_login_attempts', NEW.max_failed_login_attempts, 'lockout_duration_minutes', NEW.lockout_duration_minutes, 'password_min_length', NEW.password_min_length));
            END
        SQL);

        DB::statement('DROP TABLE IF EXISTS password_histories');
        DB::statement('ALTER TABLE users DROP COLUMN password_changed_at');
        DB::statement('ALTER TABLE security_settings
            DROP COLUMN password_history_count,
            DROP COLUMN password_expiry_days,
            DROP COLUMN password_require_uppercase,
            DROP COLUMN password_require_lowercase,
            DROP COLUMN password_require_number,
            DROP COLUMN password_require_symbol');
    }
};
