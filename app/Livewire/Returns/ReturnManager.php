<?php

namespace App\Livewire\Returns;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Services\ReturnService;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class ReturnManager extends Component
{
    use WithPagination, AuthorizesModuleActions;

    public string $mode = 'list';

    public string $search = '';

    public string $saleSearch = '';

    public ?int $foundSaleId = null;

    public string $saleSearchError = '';

    /** @var array<int, array{sale_line_item_id: int, product_name: string, unit_price: float, max_returnable: float, quantity: string, condition_type: string, reason: string}> */
    public array $returnLines = [];

    public string $overallReason = '';

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.returns.return-manager', [
            'returns' => $this->mode === 'list' ? $this->returnsQuery() : null,
            'foundSale' => $this->foundSaleId ? Sale::with(['customer', 'cashier'])->find($this->foundSaleId) : null,
        ]);
    }

    private function returnsQuery()
    {
        return SalesReturn::with(['originalSale', 'processedBy'])
            ->when($this->search, fn ($q) => $q->where('return_number', 'like', "%{$this->search}%")
                ->orWhereHas('originalSale', fn ($sq) => $sq->where('receipt_number', 'like', "%{$this->search}%")))
            ->orderByDesc('created_at')
            ->paginate(10);
    }

    public function startProcessing(): void
    {
        $this->authorizeAction('returns', 'create');

        $this->mode = 'process';
        $this->saleSearch = '';
        $this->saleSearchError = '';
        $this->foundSaleId = null;
        $this->returnLines = [];
        $this->overallReason = '';
    }

    public function cancelProcessing(): void
    {
        $this->mode = 'list';
    }

    public function findSale(): void
    {
        $this->saleSearchError = '';
        $this->foundSaleId = null;
        $this->returnLines = [];

        $sale = Sale::with(['lineItems.product'])
            ->where('receipt_number', trim($this->saleSearch))
            ->first();

        if (! $sale) {
            $this->saleSearchError = 'No sale found with that receipt number.';

            return;
        }

        if ($sale->status !== 'completed') {
            $this->saleSearchError = "This sale is {$sale->status} — only completed sales can have a return processed.";

            return;
        }

        $returnableLines = $sale->lineItems
            ->map(function ($line) {
                $remaining = $line->remainingReturnableQty();

                return $remaining > 0 ? [
                    'sale_line_item_id' => $line->id,
                    'product_name' => $line->product->name,
                    'unit_price' => (float) $line->unit_price,
                    'max_returnable' => $remaining,
                    'quantity' => '',
                    'condition_type' => 'sellable',
                    'reason' => '',
                ] : null;
            })
            ->filter()
            ->values()
            ->all();

        if (empty($returnableLines)) {
            $this->saleSearchError = 'Every line on this sale has already been fully returned.';

            return;
        }

        $this->foundSaleId = $sale->id;
        $this->returnLines = $returnableLines;
    }

    public function submitReturn(ReturnService $service): void
    {
        $this->authorizeAction('returns', 'create');

        $this->validate([
            'returnLines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'returnLines.*.condition_type' => ['required', 'in:sellable,damaged'],
        ]);

        $sale = Sale::findOrFail($this->foundSaleId);

        try {
            $salesReturn = $service->processReturn($sale, $this->returnLines, $this->overallReason ?: null, auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('flash-message', message: $e->getMessage(), variant: 'error');

            return;
        }

        $this->mode = 'list';
        $this->dispatch('flash-message', message: "{$salesReturn->return_number} processed — refund {$salesReturn->refund_amount}.", variant: 'success');
    }
}
