<?php

namespace Tests\Feature;

use App\Livewire\Roles\RoleManager;
use App\Livewire\Users\UserManager;
use App\Livewire\Users\UserProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Two privilege-escalation paths found during a security audit:
 *
 *  - Any role holding nothing more than users,update could promote any
 *    account, including its own, to Administrator — UserManager and
 *    UserProfile only ever validated roleId as "a role that exists".
 *  - Any role holding nothing more than roles,update could grant itself
 *    (or any other role) every permission in the catalog, or edit the
 *    Administrator role itself — RoleManager::save() never checked that
 *    the editor already held what it was granting.
 */
class RoleEscalationTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private function roleWith(array $grants, string $name = null): Role
    {
        $role = Role::create(['name' => $name ?? 'Custom '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $role->permissions()->attach(collect($grants)->map(
            fn ($g) => Permission::where('module', $g[0])->where('action', $g[1])->value('id')
        ));

        return $role;
    }

    private static int $phoneSeq = 100000000;

    /** UserManager/UserProfile::save() requires a real 9-digit phone. */
    private function userInRole(Role $role, array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => $role->id,
            'phone' => '+220'.(string) (self::$phoneSeq++),
        ], $attrs));
    }

    // --------------------------------------------------------- UserManager

    public function test_a_user_with_only_users_update_cannot_promote_themselves_to_administrator(): void
    {
        $role = $this->roleWith([['users', 'update']], 'Supervisor');
        $supervisor = $this->userInRole($role, ['name' => 'Sup']);
        $adminRoleId = Role::where('name', 'Administrator')->value('id');

        Livewire::actingAs($supervisor)->test(UserManager::class)
            ->call('edit', $supervisor->id)
            ->set('roleId', $adminRoleId)
            ->call('save')
            ->assertHasErrors('roleId');

        $this->assertSame($role->id, $supervisor->fresh()->role_id);
    }

    public function test_a_user_with_only_users_update_cannot_promote_someone_else_to_administrator(): void
    {
        $role = $this->roleWith([['users', 'update']], 'Supervisor');
        $supervisor = $this->userInRole($role);
        $target = $this->userInRole($role);
        $adminRoleId = Role::where('name', 'Administrator')->value('id');

        Livewire::actingAs($supervisor)->test(UserManager::class)
            ->call('edit', $target->id)
            ->set('roleId', $adminRoleId)
            ->call('save')
            ->assertHasErrors('roleId');

        $this->assertSame($role->id, $target->fresh()->role_id);
    }

    public function test_a_user_cannot_change_their_own_role_even_to_another_ordinary_role(): void
    {
        $role = $this->roleWith([['users', 'update']], 'Supervisor');
        $otherRole = $this->roleWith([['products', 'view']], 'Clerk');
        $supervisor = $this->userInRole($role);

        Livewire::actingAs($supervisor)->test(UserManager::class)
            ->call('edit', $supervisor->id)
            ->set('roleId', $otherRole->id)
            ->call('save')
            ->assertHasErrors('roleId');

        $this->assertSame($role->id, $supervisor->fresh()->role_id);
    }

    public function test_editing_your_own_other_details_still_works_without_touching_role(): void
    {
        $role = $this->roleWith([['users', 'update']], 'Supervisor');
        $supervisor = $this->userInRole($role, ['name' => 'Old Name']);

        Livewire::actingAs($supervisor)->test(UserManager::class)
            ->call('edit', $supervisor->id)
            ->set('name', 'New Name')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('New Name', $supervisor->fresh()->name);
        $this->assertSame($role->id, $supervisor->fresh()->role_id);
    }

    public function test_an_administrator_can_still_promote_someone_to_administrator(): void
    {
        $admin = User::factory()->create();
        $role = $this->roleWith([['products', 'view']], 'Clerk');
        $target = $this->userInRole($role);
        $adminRoleId = Role::where('name', 'Administrator')->value('id');

        Livewire::actingAs($admin)->test(UserManager::class)
            ->call('edit', $target->id)
            ->set('roleId', $adminRoleId)
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame($adminRoleId, $target->fresh()->role_id);
    }

    // --------------------------------------------------------- UserProfile

    public function test_user_profile_page_blocks_the_same_self_escalation(): void
    {
        $role = $this->roleWith([['users', 'view'], ['users', 'update']], 'Supervisor');
        $supervisor = $this->userInRole($role);
        $adminRoleId = Role::where('name', 'Administrator')->value('id');

        $this->actingAs($supervisor);

        Livewire::test(UserProfile::class, ['user' => $supervisor])
            ->call('editDetails')
            ->set('roleId', $adminRoleId)
            ->call('save')
            ->assertHasErrors('roleId');

        $this->assertSame($role->id, $supervisor->fresh()->role_id);
    }

    public function test_user_profile_page_blocks_promoting_someone_else_to_administrator(): void
    {
        $role = $this->roleWith([['users', 'view'], ['users', 'update']], 'Supervisor');
        $supervisor = $this->userInRole($role);
        $target = $this->userInRole($role);
        $adminRoleId = Role::where('name', 'Administrator')->value('id');

        $this->actingAs($supervisor);

        Livewire::test(UserProfile::class, ['user' => $target])
            ->call('editDetails')
            ->set('roleId', $adminRoleId)
            ->call('save')
            ->assertHasErrors('roleId');

        $this->assertSame($role->id, $target->fresh()->role_id);
    }

    // ---------------------------------------------------------- RoleManager

    public function test_a_role_with_only_roles_update_cannot_grant_a_permission_it_doesnt_have(): void
    {
        $actorRole = $this->roleWith([['roles', 'update']], 'HR');
        $actor = User::factory()->create(['role_id' => $actorRole->id]);

        $financialsId = Permission::where('module', 'financials')->where('action', 'view')->value('id');

        Livewire::actingAs($actor)->test(RoleManager::class)
            ->call('edit', $actorRole->id)
            ->set('selectedPermissions', [$financialsId])
            ->call('save')
            ->assertHasErrors('selectedPermissions');

        $this->assertFalse($actorRole->fresh()->permissions->contains('id', $financialsId));
    }

    public function test_a_role_with_only_roles_update_cannot_grant_another_role_a_permission_it_doesnt_have(): void
    {
        $actorRole = $this->roleWith([['roles', 'update']], 'HR');
        $actor = User::factory()->create(['role_id' => $actorRole->id]);
        $otherRole = $this->roleWith([['products', 'view']], 'Clerk');

        $financialsId = Permission::where('module', 'financials')->where('action', 'view')->value('id');

        Livewire::actingAs($actor)->test(RoleManager::class)
            ->call('edit', $otherRole->id)
            ->set('selectedPermissions', [$financialsId])
            ->call('save')
            ->assertHasErrors('selectedPermissions');

        $this->assertFalse($otherRole->fresh()->permissions->contains('id', $financialsId));
    }

    public function test_a_role_with_only_roles_update_cannot_modify_the_administrator_role(): void
    {
        $actorRole = $this->roleWith([['roles', 'update']], 'HR');
        $actor = User::factory()->create(['role_id' => $actorRole->id]);
        $adminRole = Role::where('name', 'Administrator')->firstOrFail();

        Livewire::actingAs($actor)->test(RoleManager::class)
            ->call('edit', $adminRole->id)
            ->set('description', 'tampered')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertNotSame('tampered', $adminRole->fresh()->description);
    }

    public function test_a_role_with_only_roles_update_cannot_create_a_role_named_administrator(): void
    {
        $actorRole = $this->roleWith([['roles', 'update'], ['roles', 'create']], 'HR');
        $actor = User::factory()->create(['role_id' => $actorRole->id]);

        Livewire::actingAs($actor)->test(RoleManager::class)
            ->call('create')
            ->set('name', 'Administrator')
            ->call('save')
            ->assertHasErrors('name');

        $this->assertSame(1, Role::where('name', 'Administrator')->count());
    }

    public function test_a_role_with_roles_update_can_still_grant_permissions_it_already_has(): void
    {
        $actorRole = $this->roleWith([['roles', 'update'], ['products', 'view']], 'HR');
        $actor = User::factory()->create(['role_id' => $actorRole->id]);
        $otherRole = $this->roleWith([], 'Clerk');

        $productsViewId = Permission::where('module', 'products')->where('action', 'view')->value('id');

        Livewire::actingAs($actor)->test(RoleManager::class)
            ->call('edit', $otherRole->id)
            ->set('selectedPermissions', [$productsViewId])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($otherRole->fresh()->permissions->contains('id', $productsViewId));
    }

    public function test_an_administrator_can_still_grant_any_permission_via_role_manager(): void
    {
        $admin = User::factory()->create();
        $role = $this->roleWith([], 'Clerk');
        $financialsId = Permission::where('module', 'financials')->where('action', 'view')->value('id');

        Livewire::actingAs($admin)->test(RoleManager::class)
            ->call('edit', $role->id)
            ->set('selectedPermissions', [$financialsId])
            ->call('save')
            ->assertHasNoErrors();

        $this->assertTrue($role->fresh()->permissions->contains('id', $financialsId));
    }
}
