<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Same treatment as the equivalent users.phone migration: column stays
 * nullable (a UNIQUE index still allows any number of NULLs), required-ness
 * and the +220 format are enforced at the application layer (CustomerManager),
 * this just backs it with real DB-level uniqueness. Existing customers
 * already had non-blank phone numbers (Faker placeholders, not real data),
 * just not in +220 format — reformatted to the same obviously-fake
 * sequential pattern used for users so they're consistent and clearly not
 * real numbers.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('customers')->orderBy('id')->get(['id'])->each(function ($customer, $index) {
            DB::table('customers')->where('id', $customer->id)->update([
                'phone' => sprintf('+220%07d', $index + 1),
            ]);
        });

        Schema::table('customers', function (Blueprint $table) {
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
    }
};
