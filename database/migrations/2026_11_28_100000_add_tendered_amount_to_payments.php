<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * Only ever meaningful for a cash sale — what the cashier actually typed
 * into "Amount tendered" at the till. Change due is never stored
 * separately; it's just tendered_amount minus the sale's own total_amount,
 * computed wherever it's shown (the receipt, the on-screen preview) so
 * there's only ever one source of truth for the total itself.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->decimal('tendered_amount', 14, 2)->nullable()->after('payment_confirmed');
        });
    }

    public function down(): void
    {
        Schema::table('payments', function (Blueprint $table) {
            $table->dropColumn('tendered_amount');
        });
    }
};
