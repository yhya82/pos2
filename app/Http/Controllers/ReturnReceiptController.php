<?php

namespace App\Http\Controllers;

use App\Models\SalesReturn;
use Illuminate\View\View;

class ReturnReceiptController extends Controller
{
    public function show(SalesReturn $salesReturn): View
    {
        abort_unless(auth()->user()->hasPermission('returns', 'view'), 403);

        $receipt = $salesReturn->receipt()->firstOrFail();

        return view('returns.receipt', [
            'salesReturn' => $salesReturn,
            'receipt' => $receipt,
        ]);
    }
}
