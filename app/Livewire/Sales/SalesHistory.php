<?php

namespace App\Livewire\Sales;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\Sale;
use App\Services\SaleService;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class SalesHistory extends Component
{
    use WithPagination, AuthorizesModuleActions;

    public string $search = '';

    public string $statusFilter = '';

    public string $dateFrom = '';

    public string $dateTo = '';

    public ?int $saleIdPendingVoid = null;

    public string $voidReason = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.sales.sales-history', [
            'sales' => Sale::with(['cashier', 'customer', 'payment.paymentMethod'])
                ->when(auth()->user()->isCashier(), fn ($q) => $q->where('cashier_id', auth()->id()))
                ->when($this->search, fn ($q) => $q->where(function ($query) {
                    $query->where('receipt_number', 'like', "%{$this->search}%")
                        ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$this->search}%"));
                }))
                ->when($this->statusFilter, fn ($q) => $q->where('status', $this->statusFilter))
                ->when($this->dateFrom, fn ($q) => $q->whereDate('sale_date', '>=', $this->dateFrom))
                ->when($this->dateTo, fn ($q) => $q->whereDate('sale_date', '<=', $this->dateTo))
                ->orderByDesc('sale_date')
                ->paginate(15),
        ]);
    }

    public function confirmVoid(int $saleId): void
    {
        $this->authorizeAction('sales', 'update');

        $this->saleIdPendingVoid = $saleId;
        $this->voidReason = '';
        $this->dispatch('open-modal', 'confirm-void-sale');
    }

    public function void(SaleService $saleService): void
    {
        $this->authorizeAction('sales', 'update');

        $this->validate(['voidReason' => ['required', 'string', 'max:255']]);

        $sale = Sale::findOrFail($this->saleIdPendingVoid);

        try {
            $saleService->voidSale($sale, $this->voidReason, auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('flash-message', message: $e->getMessage(), variant: 'error');
            $this->dispatch('close-modal', 'confirm-void-sale');

            return;
        }

        $this->dispatch('close-modal', 'confirm-void-sale');
        $this->dispatch('flash-message', message: "{$sale->receipt_number} voided.", variant: 'success');
        $this->saleIdPendingVoid = null;
    }
}
