<?php

namespace App\Livewire\Sales;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\Sale;
use App\Rules\WholeNumber;
use App\Services\ReturnService;
use Livewire\Attributes\On;
use Livewire\Component;
use RuntimeException;

/**
 * The Sales page's own "Refund" action — opens right there as a modal
 * instead of navigating to the Returns page, for the one case that page's
 * own ?receipt= deep link already covered (a known sale, picked from a
 * list, not typed in or browsed for). Shares its actual business logic
 * (ReturnService::returnableLines()/processReturn()) with ReturnManager
 * rather than re-deriving "what's left to return" a second way.
 */
class RefundModal extends Component
{
    use AuthorizesModuleActions;

    public ?int $saleId = null;

    /** @var array<int, array{sale_line_item_id: int, product_name: string, unit_price: float, max_returnable: float, original_qty: float, already_returned: float, quantity: string, condition_type: string}> */
    public array $returnLines = [];

    public string $overallReason = '';

    public string $error = '';

    #[On('confirm-refund')]
    public function open(int $saleId, ReturnService $returnService): void
    {
        $this->authorizeAction('returns', 'create');

        $sale = Sale::visibleTo(auth()->user())->with(['lineItems.product'])->findOrFail($saleId);

        abort_unless(auth()->user()->canRefundSale($sale), 403);

        $this->saleId = null;
        $this->returnLines = [];
        $this->overallReason = '';
        $this->error = '';
        $this->resetErrorBag();

        if (! in_array($sale->status, ['completed', 'refunded'], true)) {
            $this->error = "This sale is {$sale->status} — only completed or already-refunded sales can have a return processed.";
            $this->dispatch('open-modal', 'refund-sale');

            return;
        }

        if ($returnService->isPastReturnWindow($sale) && ! auth()->user()->hasPermission('returns', 'update')) {
            $this->error = 'This sale is outside the return window and can no longer be returned.';
            $this->dispatch('open-modal', 'refund-sale');

            return;
        }

        $returnableLines = $returnService->returnableLines($sale);

        if (empty($returnableLines)) {
            $this->error = 'Every line on this sale has already been fully returned.';
            $this->dispatch('open-modal', 'refund-sale');

            return;
        }

        $this->saleId = $saleId;
        $this->returnLines = $returnableLines;
        $this->dispatch('open-modal', 'refund-sale');
    }

    public function submit(ReturnService $returnService): void
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

        $sale = Sale::visibleTo(auth()->user())->findOrFail($this->saleId);

        try {
            $salesReturn = $returnService->processReturn($sale, $this->returnLines, $this->overallReason, auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('flash-message', message: $e->getMessage(), variant: 'error');

            return;
        }

        $this->dispatch('close-modal', 'refund-sale');
        $this->dispatch('flash-message', message: "{$salesReturn->return_number} processed — refund {$salesReturn->refund_amount}.", variant: 'success');
        // RefundModal and SalesHistory are separate Livewire components —
        // without this, the row's status badge, "items returned" subnote,
        // and the Refund button's own visibility all stay stale until the
        // page is manually reloaded.
        $this->dispatch('refund-processed');
        $this->reset(['saleId', 'returnLines', 'overallReason']);
    }

    public function render()
    {
        return view('livewire.sales.refund-modal', [
            'sale' => $this->saleId ? Sale::with('customer')->find($this->saleId) : null,
        ]);
    }
}
