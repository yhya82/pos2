<?php

namespace Tests\Feature;

use App\Livewire\Settings\SettingsManager;
use App\Models\Batch;
use App\Models\GeneralSetting;
use App\Models\PaymentMethod;
use App\Models\Product;
use App\Models\User;
use App\Services\SaleService;
use Livewire\Livewire;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

/**
 * The store's own contact number follows the same rule as everyone else's:
 * required, nine digits, +220 added automatically — and it prints on receipts
 * in the readable form.
 */
class StorePhoneTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->admin = User::factory()->create();
    }

    private function saveWith(string $phone)
    {
        return Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('general.contact_phone', $phone)
            ->call('saveGeneral');
    }

    public function test_nine_digits_are_accepted_and_stored_with_the_prefix(): void
    {
        $this->saveWith('831234567')->assertHasNoErrors();

        $this->assertSame('+220831234567', GeneralSetting::current()->fresh()->contact_phone);
    }

    public function test_anything_that_is_not_exactly_nine_digits_is_refused(): void
    {
        foreach (['1234567', '12345678', '1234567890', '83123456a', '831 234 567', '+220831234567', ''] as $bad) {
            $this->saveWith($bad)->assertHasErrors('general.contact_phone');
        }

        $this->assertNotSame('+2201234567', GeneralSetting::current()->fresh()->contact_phone);
    }

    public function test_the_messages_are_plain_english(): void
    {
        $this->saveWith('1234567')->assertSee('Phone number must be exactly 9 digits');
        $this->saveWith('')->assertSee('A store phone number is required');
    }

    public function test_a_refused_phone_stops_the_whole_general_save(): void
    {
        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->set('general.business_name', 'Renamed Shop')
            ->set('general.contact_phone', '123')
            ->call('saveGeneral')
            ->assertHasErrors('general.contact_phone');

        $this->assertNotSame('Renamed Shop', GeneralSetting::current()->fresh()->business_name);
    }

    public function test_a_stored_number_shows_in_the_box_without_its_prefix(): void
    {
        GeneralSetting::current()->update(['contact_phone' => '+220831234567']);

        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->assertSet('general.contact_phone', '831234567')
            ->assertSet('contactPhoneNeedsUpdate', false)
            ->assertDontSee('No store phone number is set yet');
    }

    public function test_an_empty_store_phone_is_flagged(): void
    {
        GeneralSetting::current()->update(['contact_phone' => '']);

        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->assertSet('general.contact_phone', '')
            ->assertSet('contactPhoneNeedsUpdate', true)
            ->assertSee('No store phone number is set yet');
    }

    public function test_an_old_number_is_flagged_shown_as_it_is_and_must_be_replaced(): void
    {
        GeneralSetting::current()->update(['contact_phone' => '1234567']);

        $component = Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->assertSet('general.contact_phone', '')
            ->assertSet('legacyContactPhone', '1234567')
            ->assertSee('On file: 1234567');

        $component->call('saveGeneral')->assertHasErrors('general.contact_phone');
        $this->assertSame('1234567', GeneralSetting::current()->fresh()->contact_phone);

        $component->set('general.contact_phone', '839999999')->call('saveGeneral')->assertHasNoErrors()
            ->assertSet('contactPhoneNeedsUpdate', false)
            ->assertSet('legacyContactPhone', '')
            ->assertDontSee('On file:');
    }

    public function test_the_other_settings_sections_are_not_held_up_by_the_phone(): void
    {
        GeneralSetting::current()->update(['contact_phone' => '']);

        Livewire::actingAs($this->admin)->test(SettingsManager::class)
            ->call('saveSales')->assertHasNoErrors();
    }

    public function test_the_display_form_used_on_receipts(): void
    {
        $general = new GeneralSetting(['contact_phone' => '+220831234567']);
        $this->assertSame('+220 831234567', $general->contactPhoneDisplay());

        $old = new GeneralSetting(['contact_phone' => '1234567']);
        $this->assertSame('1234567', $old->contactPhoneDisplay(), 'whatever is on file, when it is not a valid number');

        $none = new GeneralSetting(['contact_phone' => '']);
        $this->assertNull($none->contactPhoneDisplay());
    }

    public function test_a_receipt_prints_the_store_number_in_the_readable_form(): void
    {
        GeneralSetting::current()->update(['contact_phone' => '+220831234567']);

        $product = Product::factory()->create(['selling_price' => 10, 'cost_price' => 6]);
        Batch::factory()->for($product)->remaining(5)->create(['unit_cost' => 5]);

        $sale = app(SaleService::class)->completeSale(
            cartLines: [['product_id' => $product->id, 'quantity' => 1]],
            customerId: null,
            paymentMethodId: PaymentMethod::where('code', 'cash')->firstOrFail()->id,
            referenceNumber: null,
            discountType: 'none', discountValue: 0, discountReason: null,
            cashier: $this->admin,
        );

        $this->assertSame('+220 831234567', $sale->receipt()->firstOrFail()->business_snapshot['contact_phone']);
    }
}
