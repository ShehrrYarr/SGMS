@extends('pdf.layout')
@section('title', 'Payment Voucher')
@section('content')
    <table class="info">
        <tr>
            <td style="width:60%">
                <div class="muted">Paid to</div>
                <div class="bold" style="font-size:13px">{{ $vendor->name }}</div>
                @if ($vendor->company)<div>{{ $vendor->company }}</div>@endif
                @if ($vendor->phone)<div class="muted">{{ $vendor->phone }}</div>@endif
            </td>
            <td class="right">
                <div><span class="muted">Voucher #</span> <span class="bold">PV-{{ str_pad($entry->id, 6, '0', STR_PAD_LEFT) }}</span></div>
                <div><span class="muted">Date</span> {{ $entry->transaction_date->format('d M Y') }}</div>
                @if ($entry->reference_no)<div><span class="muted">Reference</span> {{ $entry->reference_no }}</div>@endif
            </td>
        </tr>
    </table>

    <div class="box mt">
        <table class="summary">
            <tr><td class="muted">Amount paid</td><td class="right bold" style="font-size:16px">{{ money($entry->debit) }}</td></tr>
            <tr><td class="muted">Paid from</td><td class="right">{{ $entry->paymentAccount?->account_name ?? '—' }}</td></tr>
            <tr><td class="muted">Against</td><td class="right">{{ $entry->purchase?->lot_number ?? 'Vendor account' }}</td></tr>
            <tr><td class="muted">Balance after payment</td><td class="right bold">{{ money($entry->running_balance) }}</td></tr>
            @if ($entry->note)<tr><td class="muted">Note</td><td class="right">{{ $entry->note }}</td></tr>@endif
        </table>
    </div>

    @if ($entry->purchasePayments->count() > 1)
        <table class="grid mt">
            <thead><tr><th>Allocated to lot</th><th class="right">Amount</th></tr></thead>
            <tbody>
                @foreach ($entry->purchasePayments as $allocation)
                    <tr><td>{{ $allocation->purchase->lot_number }}</td><td class="right">{{ number_format($allocation->amount, 2) }}</td></tr>
                @endforeach
            </tbody>
        </table>
    @endif

    <table style="margin-top:60px">
        <tr>
            <td class="center" style="width:50%"><div style="border-top:1px solid #94a3b8;width:70%;margin:0 auto;padding-top:4px">Prepared by {{ $entry->creator?->name }}</div></td>
            <td class="center"><div style="border-top:1px solid #94a3b8;width:70%;margin:0 auto;padding-top:4px">Received by (Vendor)</div></td>
        </tr>
    </table>
@endsection
