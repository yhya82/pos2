<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Closes a real gap found while building Phase 10's Appearance Settings:
 * SRS Sec. 20.18 explicitly requires the light/dark choice to be "saved as
 * a per-user preference," but the reviewed schema never gave users
 * anywhere to store one. Nullable and additive — NULL means "follow the
 * system preference," matching how the app already behaved before this
 * column existed.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->enum('theme', ['light', 'dark', 'system'])->nullable()->after('status');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn('theme');
        });
    }
};
