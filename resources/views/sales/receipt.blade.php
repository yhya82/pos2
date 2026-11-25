<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Receipt {{ $receipt->receipt_number }}</title>
    <style>
        * { box-sizing: border-box; }
        body {
            font-family: ui-monospace, "Cascadia Mono", "SFMono-Regular", Consolas, "Courier New", monospace;
            max-width: 340px;
            margin: 2rem auto;
            color: #111;
            font-size: 13px;
            line-height: 1.5;
        }
        .center { text-align: center; }
        .right { text-align: right; }
        hr { border: none; border-top: 1px dashed #999; margin: 0.75rem 0; }
        table { width: 100%; border-collapse: collapse; }
        td { padding: 2px 0; vertical-align: top; }
        .totals td { padding: 1px 0; }
        .grand { font-weight: bold; font-size: 15px; }
        .print-bar { max-width: 340px; margin: 0 auto 1rem; text-align: right; }
        .print-bar button {
            font-family: inherit; font-size: 12px; padding: 6px 12px;
            background: #111; color: #fff; border: none; border-radius: 4px; cursor: pointer;
        }
        @media print {
            .print-bar { display: none; }
            body { margin: 0 auto; }
        }
            .refunded-banner { font-weight: bold; }
        .returned-line { color: #555; }
    </style>
</head>
<body @if (request()->boolean('autoprint')) onload="window.print()" @endif>
    @php
        // Live, not from the frozen snapshot — a refund can happen long
        // after this receipt was first generated. $sale->lineItems and
        // ->returnLineItems are eager-loaded by the controller.
        $totalOriginalQty = (float) $sale->lineItems->sum('quantity');
        $totalReturnedQty = (float) $sale->lineItems->sum(fn ($l) => $l->returnLineItems->sum('quantity'));
        $totalRefunded = (float) $sale->returns->sum('refund_amount');
        $isFullyRefunded = $totalReturnedQty > 0 && $totalReturnedQty >= $totalOriginalQty;
    @endphp
    <div class="print-bar">
        <button onclick="window.print()">Print</button>
    </div>

    <div class="center">
        <strong>{{ $receipt->business_snapshot['business_name'] }}</strong><br>
        @if ($receipt->business_snapshot['address'])
            {{ $receipt->business_snapshot['address'] }}<br>
        @endif
        @if ($receipt->business_snapshot['contact_phone'])
            {{ $receipt->business_snapshot['contact_phone'] }}<br>
        @endif
        @if (!empty($receipt->business_snapshot['receipt_business_info']))
            {{ $receipt->business_snapshot['receipt_business_info'] }}<br>
        @endif
    </div>

    <hr>

    <div>
        Receipt: {{ $receipt->receipt_number }}<br>
        Date: {{ $sale->sale_date->format('Y-m-d h:i A') }}<br>
        Cashier: {{ $sale->cashier->name }}<br>
        @if ($sale->customer)
            Customer: {{ $sale->customer->name }}<br>
        @endif
        @if ($sale->status === 'voided')
            <strong>*** VOIDED ***</strong><br>
        @elseif ($isFullyRefunded)
            <strong class="refunded-banner">*** FULLY REFUNDED ***</strong><br>
        @elseif ($totalReturnedQty > 0)
            <strong class="refunded-banner">*** PARTIALLY REFUNDED ***</strong><br>
        @endif
    </div>

    <hr>

    <table>
        @foreach ($receipt->line_items_snapshot as $index => $line)
            @php $returnedQty = (float) ($sale->lineItems[$index]->returnLineItems->sum('quantity') ?? 0); @endphp
            <tr>
                <td colspan="2">{{ $line['product_name'] }}</td>
            </tr>
            <tr>
                <td>{{ rtrim(rtrim($line['quantity'], '0'), '.') }} {{ $line['unit'] }} × {{ number_format($line['unit_price'], 2) }}</td>
                <td class="right">{{ number_format($line['subtotal'], 2) }}</td>
            </tr>
            @if ($returnedQty > 0)
                <tr class="returned-line">
                    <td>&nbsp;&nbsp;Returned: {{ rtrim(rtrim((string) $returnedQty, '0'), '.') }} {{ $line['unit'] }}</td>
                    <td class="right">-{{ number_format($returnedQty * (float) $line['unit_price'], 2) }}</td>
                </tr>
            @endif
        @endforeach
    </table>

    <hr>

    <table class="totals">
        <tr><td>Subtotal</td><td class="right">{{ number_format($receipt->totals_snapshot['subtotal'], 2) }}</td></tr>
        @if ((float) $receipt->totals_snapshot['discount_amount'] > 0)
            <tr><td>Discount</td><td class="right">-{{ number_format($receipt->totals_snapshot['discount_amount'], 2) }}</td></tr>
        @endif
        @if ((float) $receipt->totals_snapshot['tax_amount'] > 0)
            <tr><td>Tax</td><td class="right">{{ number_format($receipt->totals_snapshot['tax_amount'], 2) }}</td></tr>
        @endif
        <tr class="grand"><td>Total</td><td class="right">{{ $receipt->business_snapshot['currency_code'] }} {{ number_format($receipt->totals_snapshot['total_amount'], 2) }}</td></tr>
        @if ($totalRefunded > 0)
            <tr class="returned-line"><td>Refunded</td><td class="right">-{{ number_format($totalRefunded, 2) }}</td></tr>
            <tr class="grand"><td>Net Total</td><td class="right">{{ $receipt->business_snapshot['currency_code'] }} {{ number_format((float) $receipt->totals_snapshot['total_amount'] - $totalRefunded, 2) }}</td></tr>
        @endif
        <tr><td>Payment</td><td class="right">{{ $receipt->totals_snapshot['payment_method'] }}</td></tr>
    </table>

    <hr>

    <div class="center">Thank you!</div>
</body>
</html>
