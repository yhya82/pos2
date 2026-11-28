<?php

namespace Tests\Feature;

use App\Livewire\Users\UserManager;
use App\Models\Permission;
use App\Models\Role;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The only active Administrator can't be deactivated or re-roled away from
 * Administrator — either would leave the store with nobody able to manage
 * users, roles, or settings. Both the backend guard and the UI (the
 * Deactivate button, the Role select in the edit form) are covered here.
 */
class LastAdministratorProtectionTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private static int $phoneSeq = 200000000;

    protected function setUp(): void
    {
        parent::setUp();

        // The schema seeds its own active "System Administrator" account —
        // otherwise every test below would always have at least one extra
        // active Administrator beyond the ones it explicitly creates.
        User::where('email', 'admin@example.com')->update(['status' => 'inactive']);
    }

    private function userInRole(string $roleName, array $attrs = []): User
    {
        return User::factory()->create(array_merge([
            'role_id' => Role::where('name', $roleName)->firstOrFail()->id,
            'phone' => '+220'.(string) (self::$phoneSeq++),
        ], $attrs));
    }

    /**
     * An actor with users,update/delete but who is NOT an Administrator —
     * so acting through them never changes how many active Administrators
     * exist, which is the thing these tests are deliberately holding fixed.
     */
    private function userManagerActor(): User
    {
        $role = Role::create(['name' => 'User Manager '.uniqid(), 'description' => 'test', 'status' => 'active']);
        $role->permissions()->attach(collect([['users', 'view'], ['users', 'update'], ['users', 'delete']])->map(
            fn ($g) => Permission::where('module', $g[0])->where('action', $g[1])->value('id')
        ));

        return User::factory()->create([
            'role_id' => $role->id,
            'phone' => '+220'.(string) (self::$phoneSeq++),
        ]);
    }

    public function test_the_only_active_administrator_cannot_be_deactivated(): void
    {
        $admin = $this->userInRole('Administrator');
        $other = $this->userInRole('Administrator');
        $other->update(['status' => 'inactive']);

        Livewire::actingAs($this->userManagerActor())->test(UserManager::class)
            ->call('confirmDeactivate', $admin->id)
            ->assertDispatched('flash-message', message: 'This is the only active Administrator — assign another Administrator first before deactivating this account.', variant: 'error')
            ->assertNotDispatched('open-modal');

        $this->assertSame('active', $admin->fresh()->status);
    }

    public function test_an_administrator_can_be_deactivated_when_another_active_one_remains(): void
    {
        $admin = $this->userInRole('Administrator');
        $this->userInRole('Administrator');

        Livewire::actingAs($this->userManagerActor())->test(UserManager::class)
            ->call('confirmDeactivate', $admin->id)
            ->call('toggleStatus');

        $this->assertSame('inactive', $admin->fresh()->status);
    }

    public function test_the_only_active_administrators_role_cannot_be_changed_by_another_user(): void
    {
        $admin = $this->userInRole('Administrator');
        $cashierRoleId = Role::where('name', 'Cashier')->value('id');

        Livewire::actingAs($this->userManagerActor())->test(UserManager::class)
            ->call('edit', $admin->id)
            ->set('roleId', $cashierRoleId)
            ->call('save')
            ->assertHasErrors('roleId');

        $this->assertSame('Administrator', $admin->fresh()->role->name);
    }

    public function test_the_role_select_is_disabled_in_the_edit_form_for_the_only_active_administrator(): void
    {
        $admin = $this->userInRole('Administrator');

        Livewire::actingAs($this->userManagerActor())->test(UserManager::class)
            ->call('edit', $admin->id)
            ->assertSeeHtml('disabled')
            ->assertSee("assign another Administrator first before changing this account's role", false);
    }

    public function test_the_deactivate_button_is_disabled_in_the_index_for_the_only_active_administrator(): void
    {
        $this->userInRole('Administrator');

        Livewire::actingAs($this->userManagerActor())->test(UserManager::class)
            ->assertSeeHtml('This is the only active Administrator — assign another Administrator first.');
    }

    public function test_the_deactivate_button_is_not_disabled_when_another_active_administrator_remains(): void
    {
        $this->userInRole('Administrator');
        $this->userInRole('Administrator');

        Livewire::actingAs($this->userManagerActor())->test(UserManager::class)
            ->assertDontSeeHtml('This is the only active Administrator — assign another Administrator first.');
    }
}
