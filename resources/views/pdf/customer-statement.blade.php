@extends('pdf.layout')
@section('title', 'Account Statement')
@section('content')
    <table class="info">
        <tr>
            <td style="width:60%">
                <div class="bold" style="font-size:13px">{{ $customer->name }}</div>
                <div class="muted">{{ $customer->phone }}@if ($customer->email) · {{ $customer->email }}@endif</div>
                @if ($customer->address)<div class="muted">{{ $customer->address }}</div>@endif
            </td>
            <td class="right">
                <div class="muted">Period</div>
                <div class="bold">{{ \Illuminate\Support\Carbon::parse($from)->format('d M Y') }} — {{ \Illuminate\Support\Carbon::parse($to)->format('d M Y') }}</div>
                <div class="muted" style="margin-top:6px">{{ (float) $customer->current_balance < 0 ? 'Advance / credit' : 'Outstanding balance' }}</div>
                <div class="bold" style="font-size:14px">{{ money(abs((float) $customer->current_balance)) }}</div>
            </td>
        </tr>
    </table>

    <table class="grid mt">
        <thead>
            <tr><th>Date</th><th>Type</th><th>Reference</th><th>Details</th><th class="right">Debit</th><th class="right">Credit</th><th class="right">Balance</th></tr>
        </thead>
        <tbody>
            <tr><td colspan="6" class="bold">Opening balance</td><td class="right bold">{{ number_format($opening, 2) }}</td></tr>
            @foreach ($entries as $entry)
                <tr>
                    <td>{{ $entry->transaction_date->format('d M Y') }}</td>
                    <td>{{ $entry->type_label }}</td>
                    <td>{{ $entry->reference_no ?? $entry->sale?->invoice_number }}</td>
                    <td>{{ $entry->note }}</td>
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
    <p class="small muted mt">Debit = purchases charged to your account · Credit = payments and returns. A positive balance is the amount due to {{ $tenant->name }}.</p>
@endsection
