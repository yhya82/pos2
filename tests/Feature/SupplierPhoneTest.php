<?php

namespace Tests\Feature;

use App\Livewire\Suppliers\SupplierManager;
use App\Models\Supplier;
use App\Models\User;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * A supplier's phone follows the same rule as customers and users: required,
 * exactly nine digits with +220 added automatically, and never shared.
 * Numbers already on file in the old form are flagged, not trusted.
 */
class SupplierPhoneTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    private function create(string $name, string $phone)
    {
        return Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->call('create')
            ->set('name', $name)
            ->set('phone', $phone)
            ->call('save');
    }

    // ------------------------------------------------------------ the rule

    public function test_a_nine_digit_number_is_accepted_and_stored_with_the_prefix(): void
    {
        $this->create('Good Supplier', '831234567')->assertHasNoErrors();

        $this->assertDatabaseHas('suppliers', ['name' => 'Good Supplier', 'phone' => '+220831234567']);
    }

    public function test_seven_digits_are_refused(): void
    {
        $this->create('Short Supplier', '1234567')
            ->assertHasErrors(['phone' => 'digits'])
            ->assertSee('Phone number must be exactly 9 digits');

        $this->assertDatabaseMissing('suppliers', ['name' => 'Short Supplier']);
    }

    public function test_anything_that_is_not_exactly_nine_digits_is_refused(): void
    {
        foreach (['12345678', '1234567890', '83123456a', '831 234 567', '+220831234567', '555-1234', 'abcdefghi'] as $bad) {
            $this->create('Bad Supplier', $bad)->assertHasErrors('phone');
        }

        $this->assertDatabaseMissing('suppliers', ['name' => 'Bad Supplier']);
    }

    public function test_a_phone_is_required(): void
    {
        $this->create('No Phone Supplier', '')
            ->assertHasErrors(['phone' => 'required'])
            ->assertSee('A phone number is required');

        $this->assertDatabaseMissing('suppliers', ['name' => 'No Phone Supplier']);
    }

    public function test_two_suppliers_cannot_share_a_number(): void
    {
        $this->create('First', '831234567')->assertHasNoErrors();

        $this->create('Second', '831234567')
            ->assertHasErrors('phone')
            ->assertSee('already used by another supplier');

        $this->assertDatabaseMissing('suppliers', ['name' => 'Second']);
    }

    public function test_a_supplier_can_keep_its_own_number_when_edited(): void
    {
        $this->create('Keeper', '831234567');
        $supplier = Supplier::where('name', 'Keeper')->firstOrFail();

        Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->call('edit', $supplier->id)
            ->assertSet('phone', '831234567')                  // shown without the prefix
            ->set('name', 'Keeper Renamed')
            ->call('save')
            ->assertHasNoErrors();

        $this->assertSame('+220831234567', $supplier->fresh()->phone);
        $this->assertSame('Keeper Renamed', $supplier->fresh()->name);
    }

    public function test_editing_a_supplier_to_another_suppliers_number_is_refused(): void
    {
        $this->create('One', '831111111');
        $this->create('Two', '832222222');
        $two = Supplier::where('name', 'Two')->firstOrFail();

        Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->call('edit', $two->id)
            ->set('phone', '831111111')
            ->call('save')
            ->assertHasErrors('phone');

        $this->assertSame('+220832222222', $two->fresh()->phone);
    }

    public function test_the_change_is_recorded_in_the_audit_log(): void
    {
        $this->create('Audited', '831234567');
        $supplier = Supplier::where('name', 'Audited')->firstOrFail();

        Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->call('edit', $supplier->id)->set('phone', '839999999')->call('save');

        $log = \App\Models\AuditLog::where('module', 'suppliers')->where('action', 'update')->latest('id')->firstOrFail();
        $this->assertSame('+220831234567', $log->previous_value['phone']);
        $this->assertSame('+220839999999', $log->new_value['phone']);
    }

    // ------------------------------------------------- old numbers are flagged

    private function legacy(string $name, ?string $phone): Supplier
    {
        return Supplier::create(['name' => $name, 'phone' => $phone, 'status' => 'active']);
    }

    public function test_an_old_seven_digit_number_is_flagged_and_shown_as_it_is(): void
    {
        $this->legacy('Old Supplier', '1234567');

        Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->assertSee('Needs updating')
            ->assertSee('1234567')
            ->assertSeeText('1 supplier has a phone number that needs updating');
    }

    public function test_a_supplier_with_no_number_is_flagged_too(): void
    {
        $this->legacy('Nameless Number', null);

        Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->assertSee('Needs updating');
    }

    public function test_a_valid_number_is_shown_formatted_and_not_flagged(): void
    {
        $this->legacy('Fine Supplier', '+220831234567');

        Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->assertSee('+220 831234567')
            ->assertDontSee('Needs updating')
            ->assertDontSee('needs updating');
    }

    public function test_the_banner_counts_only_the_suppliers_that_need_fixing(): void
    {
        $this->legacy('Old A', '1234567');
        $this->legacy('Old B', '7654321');
        $this->legacy('Old C', '1112223');
        $this->legacy('Fine', '+220831234567');

        Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->assertSeeText('3 suppliers have a phone number that needs updating')
            ->assertViewHas('phonesToFix', 3);
    }

    public function test_editing_an_old_supplier_starts_empty_shows_the_old_number_and_needs_a_real_one(): void
    {
        $old = $this->legacy('Old Supplier', '1234567');

        $component = Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->call('edit', $old->id)
            ->assertSet('phone', '')
            ->assertSet('legacyPhone', '1234567')
            ->assertSee('On file: 1234567');

        // Saving as-is is refused: the old number can't be carried over.
        $component->call('save')->assertHasErrors(['phone' => 'required']);
        $this->assertSame('1234567', $old->fresh()->phone);

        // Once the real number is entered it saves, and the flag goes away.
        $component->set('phone', '831234567')->call('save')->assertHasNoErrors();
        $this->assertSame('+220831234567', $old->fresh()->phone);

        Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->assertDontSee('Needs updating')
            ->assertViewHas('phonesToFix', 0);
    }

    public function test_a_new_supplier_form_has_no_leftover_hint_from_an_old_one(): void
    {
        $old = $this->legacy('Old Supplier', '1234567');

        Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->call('edit', $old->id)
            ->call('create')
            ->assertSet('legacyPhone', '')
            ->assertSet('phone', '');
    }

    public function test_the_list_can_be_searched_by_phone(): void
    {
        $this->legacy('Alpha Trading', '+220831111111');
        $this->legacy('Beta Trading', '+220832222222');

        Livewire::actingAs($this->admin)->test(SupplierManager::class)
            ->set('search', '832222')
            ->assertSee('Beta Trading')
            ->assertDontSee('Alpha Trading');
    }

    public function test_the_model_knows_a_valid_number(): void
    {
        foreach (['+220831234567' => true, '831234567' => false, '1234567' => false, '+22083123456' => false, '+2208312345678' => false, null => false, '' => false] as $phone => $valid) {
            $this->assertSame($valid, (new Supplier(['phone' => $phone === '' ? null : $phone]))->hasValidPhone(), var_export($phone, true));
        }
    }
}
