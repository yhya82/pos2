<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Splits discount-granting (Set/Edit Discount on Product Profile, Bulk
 * Discounts on Inventory Overview) out of the general products.update
 * permission into its own module — see app/Livewire/Products/ProductProfile.php
 * and app/Livewire/Inventory/InventoryOverview.php, both of which switch
 * their authorizeAction()/hasPermission() calls to ('discounts','update')
 * alongside this migration.
 */
return new class extends Migration
{
    public function up(): void
    {
        $permissionId = DB::table('permissions')->insertGetId([
            'module' => 'discounts',
            'action' => 'update',
        ]);

        $adminRoleId = DB::table('roles')->where('name', 'Administrator')->value('id');

        if ($adminRoleId) {
            DB::table('role_permissions')->insert([
                'role_id' => $adminRoleId,
                'permission_id' => $permissionId,
                'granted_at' => now(),
            ]);
        }
    }

    public function down(): void
    {
        $permissionId = DB::table('permissions')
            ->where('module', 'discounts')
            ->where('action', 'update')
            ->value('id');

        if ($permissionId) {
            DB::table('role_permissions')->where('permission_id', $permissionId)->delete();
            DB::table('permissions')->where('id', $permissionId)->delete();
        }
    }
};
