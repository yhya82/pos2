<?php

namespace App\Livewire\Customers;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\Customer;
use App\Models\ModuleSetting;
use App\Services\CreditPaymentService;
use Livewire\Attributes\Locked;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class CustomerProfile extends Component
{
    use WithPagination, AuthorizesModuleActions;

    #[Locked]
    public int $customerId;

    public string $activeTab = 'purchases';

    public string $paymentAmount = '';

    public function mount(Customer $customer): void
    {
        $this->authorizeAction('customers', 'view');

        $this->customerId = $customer->id;
    }

    public function setTab(string $tab): void
    {
        $this->activeTab = $tab;
    }

    public function render()
    {
        $customer = Customer::findOrFail($this->customerId);

        return view('livewire.customers.customer-profile', [
            'customer' => $customer,
            'sales' => $this->activeTab === 'purchases'
                ? $customer->sales()->with(['payment.paymentMethod'])->orderByDesc('sale_date')->paginate(10, pageName: 'salesPage')
                : null,
            'creditTransactions' => $this->activeTab === 'credit'
                ? $customer->creditTransactions()->with('sale')->orderByDesc('created_at')->paginate(10, pageName: 'creditPage')
                : null,
            'creditModuleEnabled' => ModuleSetting::enabled('customer_credit'),
        ]);
    }

    public function recordPayment(CreditPaymentService $service): void
    {
        $this->authorizeAction('customers', 'update');

        $this->validate([
            'paymentAmount' => ['required', 'numeric', 'gt:0'],
        ]);

        $customer = Customer::findOrFail($this->customerId);

        try {
            $service->recordPayment($customer, (float) $this->paymentAmount, auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('flash-message', message: $e->getMessage(), variant: 'error');

            return;
        }

        $this->reset('paymentAmount');
        $this->dispatch('flash-message', message: 'Payment recorded.', variant: 'success');
    }
}
