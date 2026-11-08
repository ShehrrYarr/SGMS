@extends('pdf.layout')
@section('title', 'Lot Purchase')
@section('content')
    <table class="info">
        <tr>
            <td style="width:55%">
                <div class="muted">Vendor</div>
                <div class="bold" style="font-size:13px">{{ $purchase->vendor->name }}</div>
                @if ($purchase->vendor->company)<div>{{ $purchase->vendor->company }}</div>@endif
                @if ($purchase->vendor->phone)<div class="muted">{{ $purchase->vendor->phone }}</div>@endif
            </td>
            <td class="right">
                <div><span class="muted">Lot #</span> <span class="bold">{{ $purchase->lot_number }}</span></div>
                @if ($purchase->lot_name)<div>{{ $purchase->lot_name }}</div>@endif
                <div><span class="muted">Date</span> {{ $purchase->purchase_date->format('d M Y') }}</div>
                <div><span class="muted">Received at</span> {{ $purchase->branch->name }}</div>
                <div style="margin-top:4px"><span class="badge badge-{{ $purchase->payment_status }}">{{ strtoupper($purchase->payment_status) }}</span></div>
            </td>
        </tr>
    </table>

    <table class="grid mt">
        <thead>
            <tr><th>#</th><th>Item</th><th>Variant</th><th>Barcode</th><th class="right">Qty</th><th class="right">Cost</th><th class="right">Retail</th><th class="right">Total</th></tr>
        </thead>
        <tbody>
            @foreach ($purchase->items as $i => $item)
                <tr>
                    <td>{{ $i + 1 }}</td>
                    <td>{{ $item->variant->product->name }}</td>
                    <td>{{ $item->variant->variant_name }}</td>
                    <td>{{ $item->variant->barcode }}</td>
                    <td class="right">{{ $item->quantity }}</td>
                    <td class="right">{{ number_format($item->cost_per_item, 2) }}</td>
                    <td class="right">{{ number_format($item->retail_price_set, 2) }}</td>
                    <td class="right">{{ number_format($item->total_cost, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="4">Total pieces</td>
                <td class="right">{{ $purchase->total_garment_qty }}</td>
                <td colspan="2"></td>
                <td class="right">{{ number_format($purchase->items->sum('total_cost'), 2) }}</td>
            </tr>
        </tbody>
    </table>

    <table class="mt">
        <tr>
            <td style="width:55%; vertical-align:top">
                <div class="bold" style="margin-bottom:4px">Payments</div>
                <table class="grid">
                    <thead><tr><th>Date</th><th>Account</th><th>Ref</th><th class="right">Amount</th></tr></thead>
                    <tbody>
                        @forelse ($purchase->payments as $payment)
                            <tr>
                                <td>{{ $payment->payment_date->format('d M Y') }}</td>
                                <td>{{ $payment->paymentAccount->account_name }}</td>
                                <td>{{ $payment->reference_no }}</td>
                                <td class="right">{{ number_format($payment->amount, 2) }}</td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="muted">Full credit purchase — no payments yet.</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </td>
            <td style="vertical-align:top; padding-left:16px">
                <table class="summary box">
                    <tr><td class="muted">Total lot cost</td><td class="right">{{ money($purchase->total_lot_cost) }}</td></tr>
                    <tr><td class="muted">Paid</td><td class="right">{{ money($purchase->paid_amount) }}</td></tr>
                    <tr class="grand"><td>Due to vendor</td><td class="right">{{ money($purchase->due_amount) }}</td></tr>
                </table>
            </td>
        </tr>
    </table>
    @if ($purchase->notes)
        <p class="mt muted">Notes: {{ $purchase->notes }}</p>
    @endif
@endsection
