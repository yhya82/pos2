<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Backs the real-time delivery watcher (Phase 08 follow-up): almost every
 * system_notifications row is created by a DB trigger, not PHP, so there's
 * no natural place in application code to broadcast it the moment it's
 * created. A background command polls for rows where this is still NULL,
 * broadcasts them over Reverb, then stamps this — nullable and additive,
 * doesn't touch any existing trigger body.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->timestamp('broadcast_at')->nullable()->after('is_read');
        });
    }

    public function down(): void
    {
        Schema::table('system_notifications', function (Blueprint $table) {
            $table->dropColumn('broadcast_at');
        });
    }
};
