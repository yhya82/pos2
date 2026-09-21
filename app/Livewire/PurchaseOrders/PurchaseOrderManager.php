<?php

namespace App\Livewire\PurchaseOrders;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Batch;
use App\Models\ReceivingIssue;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\PurchaseReceivingService;
use App\Services\SupplierClaimService;
use Illuminate\Support\Facades\DB;
use App\Rules\WholeNumber;
use Livewire\Component;
use Livewire\WithPagination;
use RuntimeException;

class PurchaseOrderManager extends Component
{
    use WithPagination, AuthorizesModuleActions;

    public string $search = '';

    public string $statusFilter = '';

    // --- Create/edit form state ---
    public ?int $editingPoId = null;

    public ?int $supplierId = null;

    public string $orderDate = '';

    /** @var array<int, array{product_id: ?int, qty_ordered: string, purchase_unit_id: ?int, cost_price: string}> */
    public array $lines = [];

    // --- Cancel/delete confirm state ---
    public ?int $poIdPendingCancel = null;

    public ?int $poIdPendingDelete = null;

    // --- Details panel ---
    public ?int $viewingPoId = null;

    // --- Settling a supplier claim ---
    public ?int $resolvingIssueId = null;

    public string $resolveAction = 'credited';

    public string $resolveAmount = '';

    public string $resolveNote = '';

    // --- Receiving form state ---
    public ?int $receivingPoId = null;

    /** @var array<int, array{line_item_id: int, product_name: string, remaining: float, ordered: float, already_in: float, qty: string, qty_unit: string, damaged_unit: string, damaged_qty: string, damaged_reason: string, close_short: bool, short_reason: string, batch_code: string, expiry_date: string, received_date: string, unit_cost: string, selling_price: string, conversion_qty: float, units_differ: bool, selling_unit_name: string, current_price: float}> */
    public array $receivingLines = [];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    /**
     * Costs and selling prices are money the shop's books are built on, so
     * only someone allowed to edit products can set them by hand. Everyone
     * else sees the figures read-only, and the server ignores anything they
     * send for them (the inputs being read-only is not the protection).
     */
    private function canEditPrices(): bool
    {
        return auth()->user()->hasPermission('products', 'update');
    }

    /**
     * Closing a line short and settling or waiving a supplier claim write
     * money off, so they're for people who may edit products, not everyone
     * who can receive stock. Anyone can *report* damaged goods.
     */
    private function canWriteOff(): bool
    {
        return auth()->user()->hasPermission('products', 'update');
    }

    public function openResolve(int $issueId): void
    {
        $this->authorizeAction('purchase_orders', 'update');
        $this->authorizeAction('products', 'update');

        $issue = ReceivingIssue::findOrFail($issueId);

        if ($issue->claim_status !== 'owed') {
            $this->dispatch('flash-message', message: 'That claim has already been settled.', variant: 'error');

            return;
        }

        $this->resolvingIssueId = $issue->id;
        $this->resolveAction = 'credited';
        $this->resolveAmount = (string) $issue->loss_value;
        $this->resolveNote = '';
        $this->resetValidation();

        $this->dispatch('open-modal', 'resolve-claim');
    }

    public function submitResolve(SupplierClaimService $service): void
    {
        $this->authorizeAction('purchase_orders', 'update');
        $this->authorizeAction('products', 'update');

        $this->validate([
            'resolveAction' => ['required', 'in:credited,waived'],
            'resolveAmount' => ['nullable', 'numeric', 'min:0'],
            'resolveNote' => ['nullable', 'string', 'max:255'],
        ]);

        $issue = ReceivingIssue::findOrFail($this->resolvingIssueId);

        try {
            $service->resolve(
                $issue,
                $this->resolveAction,
                is_numeric($this->resolveAmount) ? (float) $this->resolveAmount : null,
                $this->resolveNote,
                auth()->user(),
            );
        } catch (RuntimeException $e) {
            $this->addError('resolveNote', $e->getMessage());

            return;
        }

        $this->reset(['resolvingIssueId', 'resolveAmount', 'resolveNote']);
        $this->dispatch('close-modal', 'resolve-claim');
        $this->dispatch('flash-message', message: 'Claim updated.', variant: 'success');
    }

    public function view(int $poId): void
    {
        PurchaseOrder::findOrFail($poId);

        $this->viewingPoId = $poId;
        $this->dispatch('open-modal', 'po-details');
    }

    /**
     * Everything the details panel needs: the whole order, and the batches
     * its deliveries actually created (what was received, when, at what cost).
     *
     * @return array{po: PurchaseOrder, batches: \Illuminate\Support\Collection, issues: \Illuminate\Support\Collection}|null
     */
    private function details(): ?array
    {
        if (! $this->viewingPoId) {
            return null;
        }

        $po = PurchaseOrder::with(['supplier', 'creator', 'approver', 'lineItems.product.sellingUnit', 'lineItems.purchaseUnit'])->find($this->viewingPoId);

        if (! $po) {
            return null;
        }

        $batches = Batch::whereIn('purchase_order_line_item_id', $po->lineItems->pluck('id'))
            ->with('product.sellingUnit')
            ->orderByDesc('received_date')
            ->orderByDesc('id')
            ->get();

        $issues = $po->receivingIssues()->with(['product.sellingUnit', 'reporter', 'resolver'])->orderByDesc('id')->get();

        return ['po' => $po, 'batches' => $batches, 'issues' => $issues];
    }

    public function render()
    {
        return view('livewire.purchase-orders.purchase-order-manager', [
            'details' => $this->details(),
            'canEditPrices' => $this->canEditPrices(),
            'canWriteOff' => $this->canWriteOff(),
            'resolvingIssue' => $this->resolvingIssueId ? ReceivingIssue::with(['product.sellingUnit', 'supplier'])->find($this->resolvingIssueId) : null,
            'purchaseOrders' => PurchaseOrder::with(['supplier', 'creator', 'lineItems.product:id,name', 'lineItems.purchaseUnit:id,name'])
                ->withCount('lineItems')
                ->when($this->search, fn ($query) => $query->where(function ($q) {
                    $q->where('po_number', 'like', "%{$this->search}%")
                        ->orWhereHas('supplier', fn ($sq) => $sq->where('name', 'like', "%{$this->search}%"));
                }))
                ->when($this->statusFilter, fn ($query) => $query->where('status', $this->statusFilter))
                ->orderByDesc('order_date')
                ->orderByDesc('id')
                ->paginate(10),
            'suppliers' => Supplier::where('status', 'active')->orderBy('name')->get(),
            'products' => Product::with(['purchaseUnit', 'sellingUnit'])->where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    protected function rules(): array
    {
        return [
            'supplierId' => ['required', 'exists:suppliers,id'],
            'orderDate' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.qty_ordered' => ['required', new WholeNumber(1)],
            'lines.*.purchase_unit_id' => ['required', 'exists:units,id'],
            'lines.*.cost_price' => ['required', 'numeric', 'min:0'],
        ];
    }

    /**
     * Auto-fills a line's unit/cost from the selected product — wire:model.live
     * on lines.*.product_id is what makes this fire immediately instead of
     * waiting for save().
     */
    public function updated(string $name): void
    {
        if (preg_match('/^lines\.(\d+)\.product_id$/', $name, $matches)) {
            $product = Product::find($this->lines[(int) $matches[1]]['product_id']);

            if ($product) {
                $this->lines[(int) $matches[1]]['purchase_unit_id'] = $product->purchase_unit_id;
                // The line's cost_price is denominated in its own purchase
                // unit (that's what a supplier invoice line is priced in),
                // but products.cost_price is always per selling unit — so
                // this default needs converting, not a straight copy.
                $this->lines[(int) $matches[1]]['cost_price'] = (string) $product->toPurchaseUnitCost((float) $product->cost_price, 'selling');
            }
        }
    }

    public function addLine(): void
    {
        $this->lines[] = ['product_id' => null, 'qty_ordered' => '', 'purchase_unit_id' => null, 'cost_price' => ''];
    }

    public function removeLine(int $index): void
    {
        unset($this->lines[$index]);
        $this->lines = array_values($this->lines);
    }

    public function create(): void
    {
        $this->authorizeAction('purchase_orders', 'create');

        $this->reset(['editingPoId', 'supplierId']);
        $this->orderDate = now()->toDateString();
        $this->lines = [];
        $this->addLine();
        $this->resetValidation();

        $this->dispatch('open-modal', 'po-form');
    }

    public function edit(int $poId): void
    {
        $this->authorizeAction('purchase_orders', 'update');

        $po = PurchaseOrder::with('lineItems')->findOrFail($poId);

        if ($po->status !== 'draft') {
            $this->dispatch('flash-message', message: 'Only draft purchase orders can be edited.', variant: 'error');

            return;
        }

        $this->editingPoId = $po->id;
        $this->supplierId = $po->supplier_id;
        $this->orderDate = $po->order_date->toDateString();
        $this->lines = $po->lineItems->map(fn ($line) => [
            'product_id' => $line->product_id,
            'qty_ordered' => (string) $line->qty_ordered,
            'purchase_unit_id' => $line->purchase_unit_id,
            'cost_price' => (string) $line->cost_price,
        ])->all();
        $this->resetValidation();

        $this->dispatch('open-modal', 'po-form');
    }

    public function save(): void
    {
        $isCreating = ! $this->editingPoId;
        $this->authorizeAction('purchase_orders', $isCreating ? 'create' : 'update');

        $validated = $this->validate();

        $canEditPrices = $this->canEditPrices();

        DB::transaction(function () use ($validated, $isCreating, $canEditPrices) {
            // Costs already on this draft, so a user who can't edit prices
            // doesn't wipe out what someone who can already set.
            $previousCosts = $isCreating
                ? collect()
                : PurchaseOrder::findOrFail($this->editingPoId)->lineItems->pluck('cost_price', 'product_id');

            if ($isCreating) {
                $po = PurchaseOrder::create([
                    'po_number' => PurchaseOrder::generatePoNumber(),
                    'supplier_id' => $validated['supplierId'],
                    'status' => 'draft',
                    'order_date' => $validated['orderDate'],
                    'created_by' => auth()->id(),
                ]);
            } else {
                $po = PurchaseOrder::findOrFail($this->editingPoId);
                $po->update([
                    'supplier_id' => $validated['supplierId'],
                    'order_date' => $validated['orderDate'],
                ]);
                // Safe to fully replace: edit() only allows this while the
                // PO is still 'draft', before any batch has ever referenced
                // one of its line items.
                $po->lineItems()->delete();
            }

            foreach ($validated['lines'] as $line) {
                $product = Product::findOrFail($line['product_id']);

                // A line is always priced in the product's own purchase unit
                // (its conversion only means anything for that pairing).
                // Without price rights the cost is the product's current one
                // — or what the draft already had — whatever was submitted.
                $cost = $canEditPrices
                    ? $line['cost_price']
                    : ($previousCosts[$product->id] ?? $product->toPurchaseUnitCost((float) $product->cost_price, 'selling'));

                // The form never lets this user change these — so a different
                // value arriving means a doctored request. Keep the record.
                if (! $canEditPrices && abs((float) $line['cost_price'] - (float) $cost) > 0.005) {
                    AuditLog::tamperIgnored("PO line cost for \"{$product->name}\"", ['cost_price' => (float) $line['cost_price']], ['cost_price' => (float) $cost]);
                }

                if ((int) $line['purchase_unit_id'] !== (int) $product->purchase_unit_id) {
                    AuditLog::tamperIgnored("PO line unit for \"{$product->name}\"", ['purchase_unit_id' => $line['purchase_unit_id']], ['purchase_unit_id' => $product->purchase_unit_id]);
                }

                $po->lineItems()->create([
                    'product_id' => $product->id,
                    'qty_ordered' => $line['qty_ordered'],
                    'purchase_unit_id' => $product->purchase_unit_id,
                    'cost_price' => $cost,
                ]);
            }

            AuditLog::record($isCreating ? 'create' : 'update', 'purchase_orders', 'PurchaseOrder', $po->id);
        });

        $this->dispatch('close-modal', 'po-form');
        $this->dispatch('flash-message', message: $isCreating ? 'Purchase order created.' : 'Purchase order updated.', variant: 'success');
    }

    public function markAsOrdered(int $poId): void
    {
        $this->authorizeAction('purchase_orders', 'update');

        $po = PurchaseOrder::findOrFail($poId);

        if ($po->status !== 'draft') {
            $this->dispatch('flash-message', message: 'Only draft purchase orders can be marked as ordered.', variant: 'error');

            return;
        }

        $po->update(['status' => 'ordered']);
        AuditLog::record('update', 'purchase_orders', 'PurchaseOrder', $po->id, ['status' => 'draft'], ['status' => 'ordered']);
        $this->dispatch('flash-message', message: "{$po->po_number} marked as ordered.", variant: 'success');
    }

    public function approve(int $poId): void
    {
        $this->authorizeAction('purchase_orders', 'update');

        $po = PurchaseOrder::findOrFail($poId);
        $po->update(['approved_by' => auth()->id(), 'approved_at' => now()]);
        AuditLog::record('approve', 'purchase_orders', 'PurchaseOrder', $po->id);
        $this->dispatch('flash-message', message: "{$po->po_number} approved.", variant: 'success');
    }

    public function confirmCancel(int $poId): void
    {
        $this->authorizeAction('purchase_orders', 'update');

        $this->poIdPendingCancel = $poId;
        $this->dispatch('open-modal', 'confirm-cancel-po');
    }

    public function cancel(): void
    {
        $this->authorizeAction('purchase_orders', 'update');

        $po = PurchaseOrder::findOrFail($this->poIdPendingCancel);

        if (! in_array($po->status, ['draft', 'ordered'], true)) {
            $this->dispatch('flash-message', message: "Can't cancel a purchase order once receiving has started.", variant: 'error');
            $this->dispatch('close-modal', 'confirm-cancel-po');

            return;
        }

        $previous = $po->only(['status']);
        $po->update(['status' => 'cancelled']);
        AuditLog::record('update', 'purchase_orders', 'PurchaseOrder', $po->id, $previous, ['status' => 'cancelled']);

        $this->dispatch('close-modal', 'confirm-cancel-po');
        $this->dispatch('flash-message', message: "{$po->po_number} cancelled.", variant: 'success');
        $this->poIdPendingCancel = null;
    }

    public function confirmDelete(int $poId): void
    {
        $this->authorizeAction('purchase_orders', 'delete');

        $this->poIdPendingDelete = $poId;
        $this->dispatch('open-modal', 'confirm-delete-po');
    }

    public function delete(): void
    {
        $this->authorizeAction('purchase_orders', 'delete');

        $po = PurchaseOrder::findOrFail($this->poIdPendingDelete);

        // The DB trigger (trg_purchase_orders_restrict_delete) already
        // enforces draft-only deletion — this check just gives a clean
        // error message instead of a raw SQL exception.
        if ($po->status !== 'draft') {
            $this->dispatch('flash-message', message: 'Only draft purchase orders can be deleted.', variant: 'error');
            $this->dispatch('close-modal', 'confirm-delete-po');

            return;
        }

        $poNumber = $po->po_number;
        $poId = $po->id;
        // What was deleted, not just that something was: the row is gone afterwards.
        $snapshot = [
            'po_number' => $po->po_number,
            'supplier' => $po->supplier?->name,
            'order_date' => $po->order_date?->toDateString(),
            'lines' => $po->lineItems()->with('product:id,name')->get()->map(fn ($l) => [
                'product' => $l->product?->name,
                'qty_ordered' => (float) $l->qty_ordered,
                'cost_price' => (float) $l->cost_price,
            ])->all(),
        ];

        $po->delete();

        AuditLog::record('delete', 'purchase_orders', 'PurchaseOrder', $poId, $snapshot);

        $this->dispatch('close-modal', 'confirm-delete-po');
        $this->dispatch('flash-message', message: "{$poNumber} deleted.", variant: 'success');
        $this->poIdPendingDelete = null;
    }

    public function openReceive(int $poId): void
    {
        $this->authorizeAction('purchase_orders', 'update');

        $po = PurchaseOrder::with(['lineItems.product.sellingUnit', 'lineItems.purchaseUnit'])->findOrFail($poId);

        if (! in_array($po->status, ['ordered', 'partially_received'], true)) {
            $this->dispatch('flash-message', message: 'Only ordered or partially received purchase orders can be received.', variant: 'error');

            return;
        }

        $this->receivingPoId = $po->id;
        $this->receivingLines = $po->lineItems
            ->filter(fn ($line) => $line->remainingQty() > 0)
            ->map(fn ($line) => [
                'line_item_id' => $line->id,
                'product_name' => $line->product->name,
                'unit_name' => $line->purchaseUnit->name,
                'remaining' => $line->remainingQty(),
                'ordered' => (float) $line->qty_ordered,
                'already_in' => round((float) $line->qty_ordered - $line->remainingQty(), 6),
                'qty' => '',
                'batch_code' => '',
                'expiry_date' => '',
                'received_date' => now()->toDateString(),
                // Pre-filled from the order and the product; change either when the
                // delivery was invoiced or is being priced differently.
                'unit_cost' => rtrim(rtrim(number_format((float) $line->cost_price, 4, '.', ''), '0'), '.') ?: '0',
                'selling_price' => rtrim(rtrim(number_format((float) $line->product->selling_price, 4, '.', ''), '0'), '.') ?: '0',
                'conversion_qty' => (float) $line->product->conversion_qty,
                'units_differ' => $line->product->purchase_unit_id !== $line->product->selling_unit_id,
                'selling_unit_name' => $line->product->sellingUnit->name,
                'current_price' => (float) $line->product->selling_price,
                // Damaged on arrival, and (for people who may write things off) closing the rest short.
                'qty_unit' => 'purchase',
                'damaged_unit' => 'purchase',
                'damaged_qty' => '',
                'damaged_reason' => '',
                'close_short' => false,
                'short_reason' => '',
            ])->values()->all();

        $this->dispatch('open-modal', 'receive-form');
    }

    public function receive(PurchaseReceivingService $service): void
    {
        $this->authorizeAction('purchase_orders', 'update');

        $po = PurchaseOrder::findOrFail($this->receivingPoId);

        $canEditPrices = $this->canEditPrices();
        $canWriteOff = $this->canWriteOff();

        // Compare what came back against what the form was given, so anything
        // a user without the rights sent for a cost, price, or close-short is
        // recorded before it's ignored.
        if (! $canEditPrices || ! $canWriteOff) {
            $orderLines = $po->lineItems()->with('product')->get()->keyBy('id');

            foreach ($this->receivingLines as $sent) {
                $orig = $orderLines->get($sent['line_item_id'] ?? null);

                if (! $orig) {
                    continue;
                }

                $name = $orig->product->name;

                if (! $canEditPrices && filled($sent['unit_cost'] ?? null) && abs((float) $sent['unit_cost'] - (float) $orig->cost_price) > 0.005) {
                    AuditLog::tamperIgnored("received cost for \"{$name}\"", ['unit_cost' => $sent['unit_cost']], ['unit_cost' => (float) $orig->cost_price]);
                }

                if (! $canEditPrices && filled($sent['selling_price'] ?? null) && abs((float) $sent['selling_price'] - (float) $orig->product->selling_price) > 0.005) {
                    AuditLog::tamperIgnored("selling price for \"{$name}\" on receiving", ['selling_price' => $sent['selling_price']], ['selling_price' => (float) $orig->product->selling_price]);
                }

                if (! $canWriteOff && ! empty($sent['close_short'])) {
                    AuditLog::tamperIgnored("closing \"{$name}\" short", ['close_short' => true], ['close_short' => false]);
                }
            }
        }

        $receipts = collect($this->receivingLines)
            ->filter(fn ($line) => filled($line['qty']) || filled($line['damaged_qty']) || ($canWriteOff && $line['close_short']))
            ->map(fn ($line) => [
                'line_item_id' => $line['line_item_id'],
                'qty' => $line['qty'],
                'batch_code' => $line['batch_code'],
                'expiry_date' => $line['expiry_date'] ?: null,
                'received_date' => $line['received_date'] ?: null,
                'qty_unit' => $line['qty_unit'] ?? 'purchase',
                'damaged_unit' => $line['damaged_unit'] ?? 'purchase',
                'damaged_qty' => filled($line['damaged_qty']) ? $line['damaged_qty'] : 0,
                'damaged_reason' => $line['damaged_reason'],
                // Only people who may write things off can give up on the rest of a line.
                'close_short' => $canWriteOff && $line['close_short'],
                'short_reason' => $line['short_reason'],
                // Without price rights: cost as ordered, price unchanged.
                'unit_cost' => $canEditPrices ? $line['unit_cost'] : null,
                'selling_price' => $canEditPrices ? $line['selling_price'] : null,
            ])->all();

        if (empty($receipts)) {
            $this->dispatch('flash-message', message: 'Enter a quantity for at least one line.', variant: 'error');

            return;
        }

        foreach ($receipts as $receipt) {
            $cost = $receipt['unit_cost'];
            $price = $receipt['selling_price'];

            if (! \App\Support\Whole::is($receipt['damaged_qty']) || (float) $receipt['damaged_qty'] < 0 || (filled($receipt['qty']) && (! \App\Support\Whole::is($receipt['qty']) || (float) $receipt['qty'] < 0))) {
                $this->dispatch('flash-message', message: 'Quantities must be whole numbers, like 3 — no decimals.', variant: 'error');

                return;
            }

            if ((filled($cost) && (! is_numeric($cost) || (float) $cost < 0)) || (filled($price) && (! is_numeric($price) || (float) $price <= 0))) {
                $this->dispatch('flash-message', message: 'Cost must be a number, 0 or more, and the selling price a number above 0.', variant: 'error');

                return;
            }
        }

        try {
            $warnings = $service->receive($po, $receipts, auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('flash-message', message: $e->getMessage(), variant: 'error');

            return;
        }

        $this->dispatch('close-modal', 'receive-form');
        $this->dispatch(
            'flash-message',
            message: "Received against {$po->po_number}.".($warnings ? ' Note: '.implode(' ', $warnings) : ''),
            variant: $warnings ? 'warning' : 'success',
        );
        $this->receivingPoId = null;
    }
}
