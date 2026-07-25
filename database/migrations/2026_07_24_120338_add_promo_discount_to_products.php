<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->enum('promo_discount_type', ['none', 'fixed', 'percentage'])->default('none')->after('selling_price');
            $table->decimal('promo_discount_value', 12, 2)->unsigned()->default(0)->after('promo_discount_type');
            $table->date('promo_starts_at')->nullable()->after('promo_discount_value');
            $table->date('promo_ends_at')->nullable()->after('promo_starts_at');
        });
    }

    public function down(): void
    {
        Schema::table('products', function (Blueprint $table) {
            $table->dropColumn(['promo_discount_type', 'promo_discount_value', 'promo_starts_at', 'promo_ends_at']);
        });
    }
};
