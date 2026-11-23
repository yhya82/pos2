<?php

namespace Tests\Feature;

use App\Livewire\Users\UserProfile;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The read-only "Role & Permissions" card beside Account Details — just the
 * role name and a total count of what it grants. Editing those grants
 * stays the Roles screen's job; this is only a glance, not a breakdown.
 */
class UserProfileRolePermissionsCardTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private function userWith(array $grants): User
    {
        $role = Role::create(['name' => 'Custom '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $ids = collect($grants)->map(fn ($g) => Permission::where('module', $g[0])->where('action', $g[1])->value('id'));
        $role->permissions()->attach($ids);

        return User::factory()->create(['role_id' => $role->id]);
    }

    public function test_the_card_shows_the_role_name_and_a_total_permission_count(): void
    {
        $admin = User::factory()->create();
        $target = $this->userWith([['products', 'view'], ['products', 'create'], ['sales', 'view']]);

        $this->actingAs($admin);

        Livewire::test(UserProfile::class, ['user' => $target])
            ->assertSee('Role &amp; Permissions', false)
            ->assertSee($target->role->name)
            ->assertSee('Permissions granted')
            ->assertSee('3');
    }

    public function test_a_role_with_no_permissions_shows_a_count_of_zero(): void
    {
        $admin = User::factory()->create();
        $target = $this->userWith([]);

        $this->actingAs($admin);

        Livewire::test(UserProfile::class, ['user' => $target])
            ->assertViewHas('permissionCount', 0);
    }
}
