@extends('pdf.layout')
@section('title', 'Vendor Ledger')
@section('content')
    <table class="info">
        <tr>
            <td style="width:60%">
                <div class="bold" style="font-size:13px">{{ $vendor->name }}</div>
                @if ($vendor->company)<div>{{ $vendor->company }}</div>@endif
                @if ($vendor->phone)<div class="muted">{{ $vendor->phone }}</div>@endif
                @if ($vendor->address)<div class="muted">{{ $vendor->address }}</div>@endif
            </td>
            <td class="right">
                <div class="muted">Period</div>
                <div class="bold">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} — {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</div>
                <div class="muted" style="margin-top:6px">Balance payable</div>
                <div class="bold" style="font-size:14px">{{ money($vendor->current_balance) }}</div>
            </td>
        </tr>
    </table>

    <table class="grid mt">
        <thead>
            <tr>
                <th>Date</th><th>Type</th><th>Reference</th><th>Details</th>
                <th class="right">Debit (Paid)</th><th class="right">Credit (Owed)</th><th class="right">Balance</th>
            </tr>
        </thead>
        <tbody>
            <tr>
                <td colspan="6" class="bold">Opening balance</td>
                <td class="right bold">{{ number_format($opening, 2) }}</td>
            </tr>
            @foreach ($entries as $entry)
                <tr>
                    <td>{{ $entry->transaction_date->format('d M Y') }}</td>
                    <td>{{ $entry->type_label }}</td>
                    <td>{{ $entry->reference_no ?? $entry->purchase?->lot_number }}</td>
                    <td>{{ $entry->note }}@if ($entry->paymentAccount) <span class="muted">({{ $entry->paymentAccount->account_name }})</span>@endif</td>
                    <td class="right">{{ (float) $entry->debit ? number_format($entry->debit, 2) : '' }}</td>
                    <td class="right">{{ (float) $entry->credit ? number_format($entry->credit, 2) : '' }}</td>
                    <td class="right">{{ number_format($entry->running_balance, 2) }}</td>
                </tr>
            @endforeach
            <tr class="total">
                <td colspan="4">Totals for period</td>
                <td class="right">{{ number_format($entries->sum('debit'), 2) }}</td>
                <td class="right">{{ number_format($entries->sum('credit'), 2) }}</td>
                <td class="right">{{ number_format($entries->last()?->running_balance ?? $opening, 2) }}</td>
            </tr>
        </tbody>
    </table>
@endsection
