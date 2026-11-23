<?php

namespace Tests\Feature;

use App\Livewire\Roles\RoleManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class RoleManagerPermissionsColumnTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_the_roles_index_shows_a_permission_count_per_role(): void
    {
        $role = Role::create(['name' => 'Custom '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $ids = Permission::whereIn('module', ['products', 'sales'])->whereIn('action', ['view', 'create'])->pluck('id');
        $role->permissions()->attach($ids);

        $admin = User::factory()->create();

        Livewire::actingAs($admin)->test(RoleManager::class)
            ->assertSeeHtml('>Permissions</th>')
            ->assertSeeText((string) $ids->count());
    }
}
