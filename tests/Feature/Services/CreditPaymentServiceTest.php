<?php

namespace Tests\Feature\Services;

use App\Models\Customer;
use App\Models\User;
use App\Services\CreditPaymentService;
use RuntimeException;
use Tests\Concerns\RefreshesDatabaseWithViews;
use Tests\TestCase;

class CreditPaymentServiceTest extends TestCase
{
    use RefreshesDatabaseWithViews;

    private CreditPaymentService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(CreditPaymentService::class);
    }

    public function test_a_payment_reduces_the_outstanding_balance(): void
    {
        $customer = Customer::factory()->withCredit(200)->create(['outstanding_balance' => 150]);
        $user = User::factory()->create();

        $transaction = $this->service->recordPayment($customer, 60, $user);

        $this->assertEquals(90, $customer->fresh()->outstanding_balance);
        $this->assertEquals(90, $transaction->balance_after);
        $this->assertSame('payment', $transaction->type);
    }

    public function test_payment_amount_must_be_positive(): void
    {
        $customer = Customer::factory()->withCredit(200)->create(['outstanding_balance' => 100]);
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('greater than zero');

        $this->service->recordPayment($customer, 0, $user);
    }

    public function test_payment_cannot_exceed_the_outstanding_balance(): void
    {
        $customer = Customer::factory()->withCredit(200)->create(['outstanding_balance' => 50]);
        $user = User::factory()->create();

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('exceeds the outstanding balance');

        $this->service->recordPayment($customer, 51, $user);
    }

    public function test_payment_writes_an_audit_log_entry(): void
    {
        $customer = Customer::factory()->withCredit(200)->create(['outstanding_balance' => 100]);
        $user = User::factory()->create();

        $this->service->recordPayment($customer, 40, $user);

        $this->assertDatabaseHas('audit_logs', [
            'module' => 'customers',
            'record_type' => 'Customer',
            'record_id' => $customer->id,
            'action' => 'payment',
        ]);
    }

    public function test_paying_off_the_full_balance_zeroes_it_out(): void
    {
        $customer = Customer::factory()->withCredit(200)->create(['outstanding_balance' => 75]);
        $user = User::factory()->create();

        $this->service->recordPayment($customer, 75, $user);

        $this->assertEquals(0, $customer->fresh()->outstanding_balance);
    }
}
