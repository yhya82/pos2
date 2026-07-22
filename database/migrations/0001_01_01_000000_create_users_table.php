<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // `users` is intentionally NOT created here — the POS schema
        // (2026_07_22_000000_import_pos_schema migration) defines its own
        // `users` table (role_id, password_hash, username, etc.) that this
        // stock table would collide with. `password_reset_tokens` and
        // `sessions` stay: this project uses Laravel's native password-reset
        // system, and `sessions` is Laravel's own framework session storage
        // (SESSION_DRIVER=database) — distinct from the schema's own
        // `login_sessions` business-level tracking table.
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('sessions', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->foreignId('user_id')->nullable()->index();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->longText('payload');
            $table->integer('last_activity')->index();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('sessions');
    }
};
