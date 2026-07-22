<?php

namespace App\Livewire\PurchaseOrders;

use App\Livewire\Concerns\AuthorizesModuleActions;
use App\Models\AuditLog;
use App\Models\Product;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Models\Unit;
use App\Services\PurchaseReceivingService;
use Illuminate\Support\Facades\DB;
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

    // --- Receiving form state ---
    public ?int $receivingPoId = null;

    /** @var array<int, array{line_item_id: int, product_name: string, remaining: float, qty: string, batch_code: string, expiry_date: string, received_date: string}> */
    public array $receivingLines = [];

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatusFilter(): void
    {
        $this->resetPage();
    }

    public function render()
    {
        return view('livewire.purchase-orders.purchase-order-manager', [
            'purchaseOrders' => PurchaseOrder::with(['supplier', 'creator'])
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
            'products' => Product::where('status', 'active')->orderBy('name')->get(),
        ]);
    }

    protected function rules(): array
    {
        return [
            'supplierId' => ['required', 'exists:suppliers,id'],
            'orderDate' => ['required', 'date'],
            'lines' => ['required', 'array', 'min:1'],
            'lines.*.product_id' => ['required', 'exists:products,id'],
            'lines.*.qty_ordered' => ['required', 'numeric', 'gt:0'],
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
                $this->lines[(int) $matches[1]]['cost_price'] = (string) $product->cost_price;
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

        DB::transaction(function () use ($validated, $isCreating) {
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
                $po->lineItems()->create([
                    'product_id' => $line['product_id'],
                    'qty_ordered' => $line['qty_ordered'],
                    'purchase_unit_id' => $line['purchase_unit_id'],
                    'cost_price' => $line['cost_price'],
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
        $po->delete();

        AuditLog::record('delete', 'purchase_orders', 'PurchaseOrder', $poId);

        $this->dispatch('close-modal', 'confirm-delete-po');
        $this->dispatch('flash-message', message: "{$poNumber} deleted.", variant: 'success');
        $this->poIdPendingDelete = null;
    }

    public function openReceive(int $poId): void
    {
        $this->authorizeAction('purchase_orders', 'update');

        $po = PurchaseOrder::with(['lineItems.product'])->findOrFail($poId);

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
                'remaining' => $line->remainingQty(),
                'qty' => '',
                'batch_code' => '',
                'expiry_date' => '',
                'received_date' => now()->toDateString(),
            ])->values()->all();

        $this->dispatch('open-modal', 'receive-form');
    }

    public function receive(PurchaseReceivingService $service): void
    {
        $this->authorizeAction('purchase_orders', 'update');

        $po = PurchaseOrder::findOrFail($this->receivingPoId);

        $receipts = collect($this->receivingLines)
            ->filter(fn ($line) => filled($line['qty']))
            ->map(fn ($line) => [
                'line_item_id' => $line['line_item_id'],
                'qty' => $line['qty'],
                'batch_code' => $line['batch_code'],
                'expiry_date' => $line['expiry_date'] ?: null,
                'received_date' => $line['received_date'] ?: null,
            ])->all();

        if (empty($receipts)) {
            $this->dispatch('flash-message', message: 'Enter a quantity for at least one line.', variant: 'error');

            return;
        }

        try {
            $service->receive($po, $receipts, auth()->user());
        } catch (RuntimeException $e) {
            $this->dispatch('flash-message', message: $e->getMessage(), variant: 'error');

            return;
        }

        $this->dispatch('close-modal', 'receive-form');
        $this->dispatch('flash-message', message: "Stock received against {$po->po_number}.", variant: 'success');
        $this->receivingPoId = null;
    }
}
