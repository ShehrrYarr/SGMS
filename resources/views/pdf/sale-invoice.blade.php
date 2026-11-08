@extends('pdf.layout')
@section('title', 'Invoice')
@section('content')
    <table class="info">
        <tr>
            <td style="width:55%">
                <div class="muted">Billed to</div>
                <div class="bold" style="font-size:13px">{{ $sale->customer_name }}</div>
                @if ($sale->customer?->phone)<div class="muted">{{ $sale->customer->phone }}</div>@endif
                @if ($sale->customer?->address)<div class="muted">{{ $sale->customer->address }}</div>@endif
            </td>
            <td class="right">
                <div><span class="muted">Invoice #</span> <span class="bold">{{ $sale->invoice_number }}</span></div>
                <div><span class="muted">Date</span> {{ $sale->sale_date->format('d M Y h:i A') }}</div>
                <div><span class="muted">Branch</span> {{ $sale->branch->name }}</div>
                <div style="margin-top:4px"><span class="badge badge-{{ $sale->payment_status }}">{{ strtoupper($sale->payment_status) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="grid mt">
        <thead>
            <tr><th>#</th><th>Item</th><th>Variant</th><th class="right">Qty</th><th class="right">Price</th><th class="right">Discount</th><th class="right">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($sale->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item->variant->product->name }}<div class="muted small">{{ $item->variant->sku }}</div></td>
                    <td>{{ $item->variant->variant_name }}</td>
                    <td class="right">{{ $item->quantity }}@if ($item->returned_quantity)<div class="muted small">{{ $item->returned_quantity }} returned</div>@endif</td>
                    <td class="right">{{ number_format($item->unit_price, 2) }}</td>
                    <td class="right">{{ (float) $item->discount ? number_format($item->discount, 2) : '—' }}</td>
                    <td class="right">{{ number_format($item->total_price, 2) }}</td>
                </tr>
            @endforeach
        </tbody>
    </table>

    <table class="mt">
        <tr>
            <td style="width:55%; vertical-align:top">
                <div class="bold" style="margin-bottom:4px">Payments</div>
                <table class="grid">
                    <thead><tr><th>Date</th><th>Method</th><th class="right">Amount</th></tr></thead>
                    <tbody>
                        @forelse ($sale->payments as $payment)
                            <tr>
                                <td>{{ $payment->payment_date->format('d M Y') }}</td>
                                <td>{{ $payment->payment_method === 'exchange' ? 'Exchange credit '.$payment->reference_no : $payment->paymentAccount?->account_name }}</td>
                                <td class="right">{{ number_format($payment->amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="3" class="muted">Sold on account (credit).</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            <td style="vertical-align:top; padding-left:16px">
                <table class="summary box">
                    <tr><td class="muted">Subtotal</td><td class="right">{{ money($sale->subtotal) }}</td></tr>
                    @if ((float) $sale->discount)<tr><td class="muted">Discount</td><td class="right">-{{ money($sale->discount) }}</td></tr>@endif
                    @if ((float) $sale->tax)<tr><td class="muted">Tax</td><td class="right">{{ money($sale->tax) }}</td></tr>@endif
                    <tr class="grand"><td>Total</td><td class="right">{{ money($sale->total_amount) }}</td></tr>
                    <tr><td class="muted">Paid</td><td class="right">{{ money($sale->paid_amount) }}</td></tr>
                    @if ((float) $sale->returned_amount)<tr><td class="muted">Returned</td><td class="right">{{ money($sale->returned_amount) }}</td></tr>@endif
                    <tr><td class="bold">Balance due</td><td class="right bold">{{ money($sale->due_amount) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
    <p class="mt center muted">{{ $tenant->setting('receipt_footer', 'Thank you for shopping with us!') }}</p>
@endsection
