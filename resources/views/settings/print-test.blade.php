<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Test Print</title>
    <style>
        * { box-sizing: border-box; }

        body {
            color: #111;
            margin: 0;
        }

        .print-bar {
            padding: 1rem;
            text-align: center;
        }
        .print-bar button {
            font-family: inherit; font-size: 13px; padding: 8px 16px;
            background: #111; color: #fff; border: none; border-radius: 4px; cursor: pointer;
        }

        @media print {
            .print-bar { display: none; }
        }

        @if ($paperSize === 'A4')
            @page { size: A4; margin: 2cm; }
            .sheet {
                font-family: ui-sans-serif, system-ui, Arial, sans-serif;
                max-width: 21cm;
                margin: 0 auto;
                padding: 2rem;
                font-size: 14px;
                line-height: 1.6;
            }
            .sheet h1 { font-size: 22px; margin: 0 0 0.25rem; }
            .meta { color: #555; font-size: 13px; margin-bottom: 1.5rem; }
            table { width: 100%; border-collapse: collapse; margin-top: 1rem; }
            th, td { text-align: left; padding: 8px 6px; border-bottom: 1px solid #ddd; }
        @else
            .sheet {
                font-family: ui-monospace, "Cascadia Mono", "SFMono-Regular", Consolas, "Courier New", monospace;
                max-width: {{ $paperSize === '58mm' ? '220px' : '300px' }};
                margin: 1.5rem auto;
                font-size: 12px;
                line-height: 1.5;
            }
            .sheet hr { border: none; border-top: 1px dashed #999; margin: 0.6rem 0; }
            .center { text-align: center; }
        @endif
    </style>
</head>
<body onload="window.print()">
    <div class="print-bar">
        <button onclick="window.print()">Print Again</button>
    </div>

    @if ($paperSize === 'A4')
        <div class="sheet">
            <h1>{{ $general->business_name }}</h1>
            <div class="meta">
                @if ($general->address) {{ $general->address }}<br> @endif
                @if ($general->contact_phone) Tel: {{ $general->contact_phone }} @endif
                @if ($general->contact_email) &middot; {{ $general->contact_email }} @endif
            </div>

            <p><strong>Test Print</strong> &mdash; generated {{ now()->format('Y-m-d H:i') }}</p>
            <p>Paper size: A4 &middot; Configured printer: {{ $printerName ?: 'not set' }}</p>

            <table>
                <thead>
                    <tr><th>Check</th><th>Expected</th></tr>
                </thead>
                <tbody>
                    <tr><td>Margins print without clipping</td><td>Text stays inside the page on all sides</td></tr>
                    <tr><td>Alignment</td><td>Left-aligned body, ruled table below</td></tr>
                    <tr><td>Printer</td><td>{{ $printerName ?: 'Select your A4 printer in the print dialog' }}</td></tr>
                </tbody>
            </table>
        </div>
    @else
        <div class="sheet">
            <div class="center">
                <strong>{{ $general->business_name }}</strong><br>
                @if ($general->address) {{ $general->address }}<br> @endif
                @if ($general->contact_phone) {{ $general->contact_phone }}<br> @endif
            </div>
            <hr>
            <div class="center"><strong>*** TEST PRINT ***</strong></div>
            <div>Date: {{ now()->format('Y-m-d H:i') }}</div>
            <div>Paper: {{ $paperSize }}</div>
            <div>Printer: {{ $printerName ?: 'not set' }}</div>
            <hr>
            <div class="center">If this printed cleanly and fits the paper width, printing is working.</div>
        </div>
    @endif
</body>
</html>
