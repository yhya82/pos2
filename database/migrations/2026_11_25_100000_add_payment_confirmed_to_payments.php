<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Replaces the free-text reference number as the thing a cashier actually
 * does for a mobile money/bank transfer sale (Aps/Wave) — there's rarely a
 * transaction ID handy to copy at the till, and reference_number was never
 * displayed anywhere to make filling it in worthwhile. This is a plain
 * tick instead: did the payment actually come through, yes or no. Cash and
 * credit have nothing to confirm, so they default to true and the column
 * is simply never surfaced for them.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->boolean('payment_confirmed')->default(true)->after('reference_number');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('payment_confirmed');
        });
    }
};
