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

        $receipt = $sale->receipt()->firstOrFail();

        return view('sales.receipt', [
            'sale' => $sale,
            'receipt' => $receipt,
        ]);
    }
}
