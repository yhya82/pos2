<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * "Seeing every sale/return, not just your own" used to be decided by a
 * role's literal name — Sale::scopeVisibleTo()/SalesReturn::scopeVisibleTo()
 * scoped to cashier_id only when $user->isCashier() (role name === 'Cashier'),
 * so any OTHER role, by construction, saw everything storewide, with no way
 * to grant or withhold that short of renaming a role to or from "Cashier".
 * That's inconsistent with the rest of this permission-table RBAC model
 * (see the financials.view/returns.update migration's own reasoning) and a
 * real gap the moment a shop creates a custom front-line role that isn't
 * literally named "Cashier" — it would see every cashier's sales with no
 * way to withhold that.
 *
 * Granted here to whichever roles already had the equivalent hardcoded
 * behaviour, so nothing changes for anyone until an administrator ticks a
 * new box: every non-Cashier role gets it (matching ! isCashier()).
 */
return new class extends Migration
{
    public function up(): void
    {
        // permissions.action is a hard ENUM('view','create','update','delete')
        // — 'view_all' isn't one of the four CRUD actions, so it has to be
        // added to the column itself first. Without this, insertOrIgnore()
        // below wouldn't error (MySQL silently coerces an out-of-enum value
        // to '' outside strict mode) — it would silently insert a permission
        // with an empty action that could never be matched by hasPermission().
        DB::statement("ALTER TABLE permissions MODIFY action ENUM('view','create','update','delete','view_all') NOT NULL");

        DB::table('permissions')->insertOrIgnore([
            ['module' => 'sales', 'action' => 'view_all', 'description' => 'See every sale and return, not just ones this user made'],
        ]);

        $permissionId = DB::table('permissions')->where('module', 'sales')->where('action', 'view_all')->value('id');

        $nonCashierRoleIds = DB::table('roles')->where('name', '<>', 'Cashier')->pluck('id');
        $now = now();

        DB::table('role_permissions')->insertOrIgnore(
            $nonCashierRoleIds->map(fn ($roleId) => [
                'role_id' => $roleId, 'permission_id' => $permissionId, 'granted_at' => $now,
            ])->all()
        );
    }

    public function down(): void
    {
        $id = DB::table('permissions')->where('module', 'sales')->where('action', 'view_all')->value('id');

        DB::table('role_permissions')->where('permission_id', $id)->delete();
        DB::table('permissions')->where('id', $id)->delete();

        DB::statement("ALTER TABLE permissions MODIFY action ENUM('view','create','update','delete') NOT NULL");
    }
};
