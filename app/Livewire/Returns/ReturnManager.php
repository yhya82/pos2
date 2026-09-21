<?php

namespace App\Livewire\Returns;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\Sale;
use App\Models\SalesReturn;
use App\Services\ReturnService;
use Livewire\Attributes\Url;
use App\Rules\WholeNumber;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class ReturnManager extends Component
{
    use WithPagination, AuthorizesModuleActions;

    public string $mode = 'list';

    public string $search = '';

    public string $saleSearch = '';

    /**
     * Populated from ?receipt=... (Sales History's "Refund" action) so a
     * cashier or admin processing a return doesn't have to retype a receipt
     * number they're already looking at.
     */
    #[Url(as: 'receipt')]
    public string $prefillReceipt = '';

    public ?int $foundSaleId = null;

    public string $saleSearchError = '';

    /** @var array<int, array{sale_line_item_id: int, product_name: string, unit_price: float, max_returnable: float, quantity: string, condition_type: string, reason: string}> */
    public array $returnLines = [];

    public string $overallReason = '';

    public function mount(): void
    {
        if ($this->prefillReceipt === '') {
            return;
        }

        $this->authorizeAction('returns', 'create');

        $this->mode = 'process';
        $this->saleSearch = $this->prefillReceipt;
        $this->findSale();
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    /**
     * saleSearch doubles as the filter for the returnable-sales list (see
     * returnableSalesQuery()) once "Process Return" has been clicked — a
     * separate paginator name keeps it from colliding with the returns
     * list's own pagination.
     */
    public function updatingSaleSearch(): void
    {
        $this->resetPage('salesPage');
    }

    public function render()
    {
        return view('livewire.returns.return-manager', [
            'returns' => $this->mode === 'list' ? $this->returnsQuery() : null,
            'foundSale' => $this->foundSaleId ? Sale::visibleTo(auth()->user())->with(['customer', 'cashier'])->find($this->foundSaleId) : null,
            'returnableSales' => ($this->mode === 'process' && ! $this->foundSaleId) ? $this->returnableSalesQuery() : null,
        ]);
    }

    private function returnsQuery()
    {
        return SalesReturn::with(['originalSale', 'processedBy'])
            ->visibleTo(auth()->user())
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->where('return_number', 'like', "%{$this->search}%")
                ->orWhereHas('originalSale', fn ($sq) => $sq->where('receipt_number', 'like', "%{$this->search}%"))))
            ->orderByDesc('created_at')
            ->paginate(10);
    }

    /**
     * "Process Return" no longer opens a blank type-the-receipt-number box
     * — it opens this list so a cashier can search/browse instead of
     * needing the exact number in hand, same idea as Sales History's own
     * per-row Refund link.
     */
    private function returnableSalesQuery()
    {
        // Only sales this user is allowed to refund: an administrator's list is
        // every sale, anyone else's is just their own.
        return Sale::with(['customer', 'cashier'])
            ->where('status', 'completed')
            ->when(! auth()->user()->isAdministrator(), fn ($q) => $q->where('cashier_id', auth()->id()))
            ->when($this->saleSearch, fn ($q) => $q->where(fn ($w) => $w->where('receipt_number', 'like', "%{$this->saleSearch}%")
                ->orWhereHas('customer', fn ($cq) => $cq->where('name', 'like', "%{$this->saleSearch}%"))))
            ->orderByDesc('sale_date')
            ->paginate(10, pageName: 'salesPage');
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

    /**
     * URL-driven entry point only now — Sales History's own per-row Refund
     * link deep-links here via ?receipt=..., picked up by mount(). The
     * in-page UI no longer exposes a "type a receipt number" box itself;
     * selectSale() below is what the returnable-sales list's row action
     * uses instead.
     */
    public function findSale(): void
    {
        $this->saleSearchError = '';
        $this->foundSaleId = null;
        $this->returnLines = [];

        $sale = Sale::visibleTo(auth()->user())->with(['lineItems.product'])
            ->where('receipt_number', trim($this->saleSearch))
            ->first();

        // Someone else's sale looks exactly like one that doesn't exist.
        if (! $sale || ! auth()->user()->canRefundSale($sale)) {
            $this->saleSearchError = 'No sale found with that receipt number.';

            return;
        }

        $this->loadSaleForReturn($sale);
    }

    public function selectSale(int $saleId): void
    {
        $sale = Sale::visibleTo(auth()->user())->with(['lineItems.product'])->findOrFail($saleId);

        abort_unless(auth()->user()->canRefundSale($sale), 403);

        $this->loadSaleForReturn($sale);
    }

    private function loadSaleForReturn(Sale $sale): void
    {
        $this->saleSearchError = '';
        $this->foundSaleId = null;
        $this->returnLines = [];

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
            'returnLines.*.quantity' => ['nullable', new WholeNumber(0)],
            'returnLines.*.condition_type' => ['required', 'in:sellable,damaged'],
            // The returns table requires it — without this a blank reason
            // surfaced as a raw database error.
            'overallReason' => ['required', 'string', 'max:255'],
        ], [
            'overallReason.required' => 'Say why this return is being processed — it goes on the return record.',
        ]);

        $sale = Sale::visibleTo(auth()->user())->findOrFail($this->foundSaleId);

        try {
            $salesReturn = $service->processReturn($sale, $this->returnLines, $this->overallReason, auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('flash-message', message: $e->getMessage(), variant: 'error');

            return;
        }

        $this->mode = 'list';
        $this->dispatch('flash-message', message: "{$salesReturn->return_number} processed — refund {$salesReturn->refund_amount}.", variant: 'success');
    }
}
