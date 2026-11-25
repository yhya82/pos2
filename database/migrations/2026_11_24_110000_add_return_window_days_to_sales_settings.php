<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;

/**
 * How many days after a sale it can still be returned — 0 (the default)
 * means no limit, same convention as security_settings.password_expiry_days.
 * A regular return still goes through ReturnService::processReturn()'s
 * normal canRefundSale() ownership check; this is a second, independent
 * gate on top of it — someone holding returns,update (which already means
 * "can refund a sale beyond their own") can process a return past the
 * window too, same as they can already refund someone else's sale.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('sales_settings', function (Blueprint $table) {
            $table->unsignedInteger('return_window_days')->default(0)->after('default_payment_method_id');
        });
    }

    public function down(): void
    {
        Schema::table('sales_settings', function (Blueprint $table) {
            $table->dropColumn('return_window_days');
        });
    }
};
