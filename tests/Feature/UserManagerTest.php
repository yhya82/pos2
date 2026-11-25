<?php

namespace Tests\Feature;

use App\Livewire\Users\UserManager;
use App\Models\Role;
use App\Models\User;
use Illuminate\Support\Facades\Hash;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * Creating a brand-new account through this screen was completely broken:
 * save()'s create path called User::create($attributes) with no
 * password_hash at all — a NOT NULL column with no default — so the
 * INSERT itself failed under strict mode before setPassword() below it
 * ever ran. No test caught it because none actually completed a
 * successful creation; the only existing one (PasswordSecurityPolicyTest)
 * deliberately omits the password to assert it's required.
 */
class UserManagerTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    public function test_creating_a_new_user_actually_succeeds(): void
    {
        $admin = User::factory()->create();
        $role = Role::where('name', 'Cashier')->firstOrFail();

        Livewire::actingAs($admin)->test(UserManager::class)
            ->call('create')
            ->set('name', 'Abdou Bah')
            ->set('username', 'abdoubah')
            ->set('email', 'abdoubah@example.com')
            ->set('phone', '831112222')
            ->set('roleId', $role->id)
            ->set('password', 'Compliant123!')
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('username', 'abdoubah')->firstOrFail();

        $this->assertSame('Abdou Bah', $user->name);
        $this->assertSame($role->id, $user->role_id);
        $this->assertTrue(Hash::check('Compliant123!', $user->password_hash));
    }

    /**
     * setPassword() must still run on create (not just a bare insert with
     * the hash) — it's what writes the PasswordHistory row and the
     * 'password_changed' security log entry.
     */
    public function test_creating_a_new_user_still_records_password_history_and_the_security_log(): void
    {
        $admin = User::factory()->create();
        $role = Role::where('name', 'Cashier')->firstOrFail();

        Livewire::actingAs($admin)->test(UserManager::class)
            ->call('create')
            ->set('name', 'Fresh Account')
            ->set('username', 'freshaccount')
            ->set('email', 'freshaccount@example.com')
            ->set('phone', '831113333')
            ->set('roleId', $role->id)
            ->set('password', 'Compliant123!')
            ->call('save')
            ->assertHasNoErrors();

        $user = User::where('username', 'freshaccount')->firstOrFail();

        $this->assertDatabaseHas('password_histories', ['user_id' => $user->id]);
        $this->assertDatabaseHas('audit_logs', ['module' => 'security', 'action' => 'password_changed', 'record_id' => $user->id]);
    }

    public function test_email_is_required_when_creating_a_user(): void
    {
        $admin = User::factory()->create();
        $role = Role::where('name', 'Cashier')->firstOrFail();

        Livewire::actingAs($admin)->test(UserManager::class)
            ->call('create')
            ->set('name', 'No Email Person')
            ->set('username', 'noemailperson')
            ->set('phone', '831115555')
            ->set('roleId', $role->id)
            ->set('password', 'Compliant123!')
            ->call('save')
            ->assertHasErrors('email');

        $this->assertDatabaseMissing('users', ['username' => 'noemailperson']);
    }

    public function test_email_is_not_required_when_editing_an_account_that_predates_the_rule(): void
    {
        $admin = User::factory()->create();
        $role = Role::where('name', 'Cashier')->firstOrFail();
        $target = User::factory()->create(['role_id' => $role->id, 'email' => null, 'phone' => '+220831116666']);

        Livewire::actingAs($admin)->test(UserManager::class)
            ->call('edit', $target->id)
            ->set('name', 'Still No Email')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('Still No Email', $target->fresh()->name);
        $this->assertNull($target->fresh()->email);
    }

    public function test_editing_an_existing_user_is_unaffected_by_the_fix(): void
    {
        $admin = User::factory()->create();
        $role = Role::where('name', 'Cashier')->firstOrFail();
        $target = User::factory()->create(['role_id' => $role->id, 'phone' => '+220831114444']);
        $originalHash = $target->password_hash;

        Livewire::actingAs($admin)->test(UserManager::class)
            ->call('edit', $target->id)
            ->set('name', 'Renamed Person')
            ->call('save')
            ->assertHasNoErrors();

        // The shared $attributes array must never carry password_hash into
        // the update path — $this->password is always empty while editing.
        $this->assertSame($originalHash, $target->fresh()->password_hash);
        $this->assertSame('Renamed Person', $target->fresh()->name);
    }
}
