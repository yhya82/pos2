<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Three capabilities that used to be hardcoded to a role's name (Cashier /
 * Administrator) become ordinary permissions, so they show up as checkboxes
 * on the Roles screen instead of only being settable by renaming a role:
 *
 *  - financials.view        — cost, profit, revenue, inventory value
 *  - returns.update         — refund any sale, not just the cashier's own
 *                              (the row already existed in the catalog,
 *                              unused, from the original CRUD seed — this
 *                              is the first thing to check it)
 *  - supplier_claims.update — close a PO line short / settle a claim
 *
 * Granted here to whichever roles already had the equivalent hardcoded
 * behaviour, so nothing changes for anyone until an administrator ticks a
 * new box: every non-Cashier role gets financials.view (matching
 * `! isCashier()`); Administrator alone gets returns.update and
 * supplier_claims.update (matching `isAdministrator()` and the
 * `products.update` reuse, which today only Administrator holds).
 */
return new class extends Migration
{
    public function up(): void
    {
        DB::table('permissions')->insertOrIgnore([
            ['module' => 'financials', 'action' => 'view', 'description' => 'See cost, profit, revenue and inventory value'],
            ['module' => 'supplier_claims', 'action' => 'update', 'description' => 'Close a purchase order line short and settle a supplier claim'],
        ]);

        // returns.update already exists in the catalog (seeded with every
        // module x CRUD action) but has never been granted or checked —
        // this is what gives it a meaning.
        DB::table('permissions')->where('module', 'returns')->where('action', 'update')
            ->update(['description' => 'Refund any sale, not just ones this user made']);

        $financialsId = DB::table('permissions')->where('module', 'financials')->where('action', 'view')->value('id');
        $returnsUpdateId = DB::table('permissions')->where('module', 'returns')->where('action', 'update')->value('id');
        $claimsId = DB::table('permissions')->where('module', 'supplier_claims')->where('action', 'update')->value('id');

        $now = now();

        // financials.view: every role except Cashier (replicates ! isCashier()).
        $nonCashierRoleIds = DB::table('roles')->where('name', '<>', 'Cashier')->pluck('id');
        DB::table('role_permissions')->insertOrIgnore(
            $nonCashierRoleIds->map(fn ($roleId) => [
                'role_id' => $roleId, 'permission_id' => $financialsId, 'granted_at' => $now,
            ])->all()
        );

        // returns.update and supplier_claims.update: Administrator only
        // (replicates isAdministrator() and the products.update reuse, which
        // today only Administrator holds).
        $adminRoleId = DB::table('roles')->where('name', 'Administrator')->value('id');

        if ($adminRoleId) {
            DB::table('role_permissions')->insertOrIgnore([
                ['role_id' => $adminRoleId, 'permission_id' => $returnsUpdateId, 'granted_at' => $now],
                ['role_id' => $adminRoleId, 'permission_id' => $claimsId, 'granted_at' => $now],
            ]);
        }
    }

    public function down(): void
    {
        $ids = DB::table('permissions')
            ->where(fn ($q) => $q->where('module', 'financials')->where('action', 'view'))
            ->orWhere(fn ($q) => $q->where('module', 'supplier_claims')->where('action', 'update'))
            ->pluck('id');

        DB::table('role_permissions')->whereIn('permission_id', $ids)->delete();
        DB::table('permissions')->whereIn('id', $ids)->delete();

        DB::table('role_permissions')->where('permission_id', function ($q) {
            $q->select('id')->from('permissions')->where('module', 'returns')->where('action', 'update');
        })->delete();

        DB::table('permissions')->where('module', 'returns')->where('action', 'update')
            ->update(['description' => null]);
    }
};
