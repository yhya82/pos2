<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use Illuminate\View\View;

class ReceiptController extends Controller
{
    /**
     * Renders from the frozen receipts JSON snapshot, not live sale/product
     * data — the whole point of that table is that a reprint months later
     * shows exactly what the customer originally saw.
     */
    public function show(Sale $sale): View
    {
        abort_unless(auth()->user()->hasPermission('sales', 'view'), 403);
        abort_if(auth()->user()->isCashier() && $sale->cashier_id !== auth()->id(), 403);

        $receipt = $sale->receipt()->firstOrFail();

        // Live, not from the snapshot — a refund can happen long after the
        // original receipt was frozen, same as the existing VOIDED banner
        // already reads $sale->status live rather than from the snapshot.
        $sale->load(['lineItems.returnLineItems', 'returns']);

        return view('sales.receipt', [
            'sale' => $sale,
            'receipt' => $receipt,
        ]);
    }
}
