<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Every existing user has a blank phone number today, so the column stays
 * nullable here (a UNIQUE index still allows any number of NULLs — MySQL
 * treats them as distinct) rather than forcing a NOT NULL that would break
 * on import. Required-ness and the +220 format are enforced at the
 * application layer (UserManager, profile form); this migration only backs
 * that with real DB-level uniqueness. Existing rows get an obviously-fake
 * placeholder (+220 + sequential digits) so the UNIQUE index has something
 * distinct to key off before real numbers are entered.
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('users')->orderBy('id')->get(['id'])->each(function ($user, $index) {
            DB::table('users')->where('id', $user->id)->update([
                'phone' => sprintf('+220%07d', $index + 1),
            ]);
        });

        Schema::table('users', function (Blueprint $table) {
            $table->unique('phone');
        });
    }

    public function down(): void
    {
        Schema::table('users', function (Blueprint $table) {
            $table->dropUnique(['phone']);
        });
    }
};
