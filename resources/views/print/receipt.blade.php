@php
    $paper = $width === '58' ? 58 : 80;
    $content = $paper === 58 ? 48 : 72; // printable width in mm
@endphp
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Receipt {{ $sale->invoice_number }}</title>
    <style>
        @page { size: {{ $paper }}mm auto; margin: 0; }
        * { box-sizing: border-box; }
        html, body { margin: 0; padding: 0; background: #fff; color: #000; }
        body { font-family: "Courier New", ui-monospace, monospace; font-size: {{ $paper === 58 ? '10.5px' : '12px' }}; line-height: 1.35; }
        .receipt { width: {{ $content }}mm; margin: 0 auto; padding: 3mm 0 6mm; }
        .center { text-align: center; }
        .right { text-align: right; }
        .bold { font-weight: 700; }
        .shop { font-size: {{ $paper === 58 ? '14px' : '17px' }}; font-weight: 700; letter-spacing: .02em; }
        .muted { color: #333; }
        hr { border: 0; border-top: 1px dashed #000; margin: 2mm 0; }
        table { width: 100%; border-collapse: collapse; }
        td { vertical-align: top; padding: .4mm 0; }
        .item-name { font-weight: 700; }
        .grand td { font-size: {{ $paper === 58 ? '13px' : '15px' }}; font-weight: 700; padding-top: 1mm; }
        .barcode { margin-top: 2mm; }
        .barcode svg { width: 100%; height: 34px; }
        .no-print { margin: 12px auto; text-align: center; font-family: system-ui, sans-serif; }
        .no-print button { padding: 8px 16px; border-radius: 6px; border: 1px solid #ccc; background: #f5f5f5; cursor: pointer; margin: 0 4px; }
        @media print { .no-print { display: none; } }
    </style>
</head>
<body>
<div class="receipt">
    <div class="center">
        <div class="shop">{{ $tenant->name }}</div>
        <div>{{ $sale->branch->name }}</div>
        @if ($sale->branch->address)<div class="muted">{{ $sale->branch->address }}</div>@endif
        @if ($sale->branch->phone ?? $tenant->phone)<div class="muted">Tel: {{ $sale->branch->phone ?? $tenant->phone }}</div>@endif
    </div>
    <hr>
    <table>
        <tr><td>Invoice</td><td class="right bold">{{ $sale->invoice_number }}</td></tr>
        <tr><td>Date</td><td class="right">{{ $sale->sale_date->format('d M Y h:i A') }}</td></tr>
        <tr><td>Cashier</td><td class="right">{{ $sale->creator?->name ?? '-' }}</td></tr>
        <tr><td>Customer</td><td class="right">{{ $sale->customer_name }}</td></tr>
        @if ($sale->customer?->phone)<tr><td>Phone</td><td class="right">{{ $sale->customer->phone }}</td></tr>@endif
    </table>
    <hr>
    <table>
        @foreach ($sale->items as $item)
            <tr><td colspan="2" class="item-name">{{ $item->variant->product->name }}</td></tr>
            <tr>
                <td>{{ $item->variant->variant_name }} &nbsp; {{ $item->quantity }} x {{ number_format($item->unit_price, 2) }}</td>
                <td class="right">{{ number_format($item->quantity * $item->unit_price, 2) }}</td>
            </tr>
            @if ((float) $item->discount > 0)
                <tr><td class="muted">&nbsp; Discount</td><td class="right">-{{ number_format($item->discount, 2) }}</td></tr>
            @endif
        @endforeach
    </table>
    <hr>
    <table>
        <tr><td>Items</td><td class="right">{{ $sale->items->sum('quantity') }}</td></tr>
        <tr><td>Subtotal</td><td class="right">{{ number_format($sale->subtotal, 2) }}</td></tr>
        @if ((float) $sale->discount > 0)<tr><td>Discount</td><td class="right">-{{ number_format($sale->discount, 2) }}</td></tr>@endif
        @if ((float) $sale->tax > 0)<tr><td>Tax ({{ rtrim(rtrim(number_format($tenant->tax_rate, 2), '0'), '.') }}%)</td><td class="right">{{ number_format($sale->tax, 2) }}</td></tr>@endif
        <tr class="grand"><td>TOTAL</td><td class="right">{{ $tenant->currency_symbol }} {{ number_format($sale->total_amount, 2) }}</td></tr>
    </table>
    <hr>
    <table>
        @foreach ($sale->payments as $payment)
            <tr>
                <td>{{ $payment->payment_method === 'exchange' ? 'Exchange credit' : ($payment->paymentAccount?->account_name ?? ucfirst($payment->payment_method)) }}</td>
                <td class="right">{{ number_format($payment->amount, 2) }}</td>
            </tr>
        @endforeach
        @if ($tendered > 0)
            <tr><td>Cash tendered</td><td class="right">{{ number_format($tendered, 2) }}</td></tr>
            <tr class="bold"><td>Change</td><td class="right">{{ number_format($change, 2) }}</td></tr>
        @endif
        @if ((float) $sale->due_amount > 0)
            <tr class="bold"><td>Balance due (on account)</td><td class="right">{{ number_format($sale->due_amount, 2) }}</td></tr>
        @endif
        @if ($sale->customer && (float) $sale->customer->current_balance > 0)
            <tr><td>Total account balance</td><td class="right">{{ number_format($sale->customer->current_balance, 2) }}</td></tr>
        @endif
    </table>
    <hr>
    <div class="center">
        <div>{{ $tenant->setting('receipt_footer', 'Thank you for shopping with us!') }}</div>
        <div class="barcode">{!! $barcode !!}</div>
        <div class="muted">{{ $sale->invoice_number }}</div>
    </div>
</div>

<div class="no-print">
    <button type="button" onclick="window.print()">Print</button>
    <button type="button" onclick="window.close()">Close</button>
</div>

@if ($autoprint)
    <script>
        window.addEventListener('load', function () {
            setTimeout(function () { window.focus(); window.print(); }, 250);
        });
    </script>
@endif
</body>
</html>
