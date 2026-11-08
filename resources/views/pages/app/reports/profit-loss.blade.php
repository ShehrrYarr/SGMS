<?php

use App\Livewire\Concerns\WithReportFilters;
use App\Services\ReportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Profit & Loss')] class extends Component {
    use WithReportFilters;

    public function export(ReportService $reports)
    {
        [$from, $to] = $this->period();
        $pnl = $reports->profitAndLoss($from, $to, $this->reportBranchIds());
        $name = 'profit-loss-'.$from->toDateString().'-to-'.$to->toDateString().'.csv';

        return response()->streamDownload(function () use ($pnl) {
            $out = fopen('php://output', 'w');
            fputcsv($out, ['Line', 'Amount']);
            foreach ([
                'Gross sales' => $pnl['gross_sales'], 'Discounts' => -$pnl['discounts'], 'Net sales (excl. tax)' => $pnl['net_sales'],
                'Returns' => -$pnl['returns'], 'Delivery charges' => $pnl['delivery'], 'Net revenue' => $pnl['net_revenue'], 'Cost of goods sold' => -$pnl['cogs'],
                'Gross profit' => $pnl['gross_profit'],
            ] as $label => $value) {
                fputcsv($out, [$label, number_format($value, 2, '.', '')]);
            }
            foreach ($pnl['expenses'] as $expense) {
                fputcsv($out, ['Expense: '.$expense['category'], number_format(-$expense['total'], 2, '.', '')]);
            }
            fputcsv($out, ['Inventory adjustments', number_format($pnl['adjustments'], 2, '.', '')]);
            fputcsv($out, ['Net profit', number_format($pnl['net_profit'], 2, '.', '')]);
            fputcsv($out, ['Sales tax collected (memo)', number_format($pnl['tax'], 2, '.', '')]);
            fclose($out);
        }, $name, ['Content-Type' => 'text/csv']);
    }

    public function with(ReportService $reports): array
    {
        [$from, $to] = $this->period();
        [$prevFrom, $prevTo] = $this->previousPeriod();
        $branchIds = $this->reportBranchIds();

        return $this->filterViewData() + [
            'pnl' => $reports->profitAndLoss($from, $to, $branchIds),
            'prev' => $reports->profitAndLoss($prevFrom, $prevTo, $branchIds),
            'prevLabel' => $prevFrom->format('d M').' – '.$prevTo->format('d M Y'),
            'trend' => $reports->monthlyTrend(12, $branchIds),
        ];
    }
}; ?>

@php
    $change = function (float $now, float $before): ?float {
        return abs($before) < 0.005 ? null : round(($now - $before) / abs($before) * 100, 1);
    };
    $delta = function (float $now, float $before, bool $goodWhenUp = true) use ($change) {
        $pct = $change($now, $before);
        if ($pct === null) {
            return '<span class="text-muted small">—</span>';
        }
        $good = $goodWhenUp ? $pct >= 0 : $pct <= 0;
        return '<span class="small fw-medium text-'.($good ? 'success' : 'danger').'"><i class="'.($pct >= 0 ? 'ri-arrow-up-line' : 'ri-arrow-down-line').'"></i>'.abs($pct).'%</span>';
    };
    $lines = [
        ['Gross sales', 'gross_sales', 1, false, true],
        ['Discounts given', 'discounts', -1, false, false],
        ['Net sales (excl. tax)', 'net_sales', 1, true, true],
        ['Returns', 'returns', -1, false, false],
        ['Delivery charges collected', 'delivery', 1, false, true],
        ['Net revenue', 'net_revenue', 1, true, true],
        ['Cost of goods sold', 'cogs', -1, false, false],
        ['Gross profit', 'gross_profit', 1, true, true],
    ];
@endphp

<div>
    @include('partials.report-toolbar', ['actions' => '<button type="button" class="btn btn-light-success" wire:click="export"><i class="ri-file-excel-2-line me-1"></i>Export CSV</button>'])

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3">
            <x-stat-card label="Net revenue" :value="money($pnl['net_revenue'])" icon="ri-money-dollar-circle-line" :hint="$pnl['invoices'].' invoices · '.$pnl['items_sold'].' pcs'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Gross profit" :value="money($pnl['gross_profit'])" icon="ri-funds-line" color="info" :hint="$pnl['gross_margin'].'% margin'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Expenses" :value="money($pnl['expenses_total'])" icon="ri-wallet-3-line" color="warning" :hint="count($pnl['expenses']).' categories'" />
        </div>
        <div class="col-6 col-xl-3">
            <x-stat-card label="Net profit" :value="money($pnl['net_profit'])" icon="ri-trophy-line" :color="$pnl['net_profit'] >= 0 ? 'success' : 'danger'" :hint="$pnl['net_margin'].'% of revenue'" />
        </div>
    </div>

    <div class="row g-3">
        <div class="col-xl-7">
            <div class="card mb-0 h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0">Profit & loss statement</h6>
                    <small class="text-muted">vs {{ $prevLabel }}</small>
                </div>
                <div class="table-responsive">
                    <table class="table mb-0 align-middle">
                        <thead class="table-light"><tr><th></th><th class="text-end">This period</th><th class="text-end">Previous</th><th class="text-end">Change</th></tr></thead>
                        <tbody class="tabular-nums">
                            @foreach ($lines as [$label, $key, $sign, $total, $goodUp])
                                <tr class="{{ $total ? 'table-light fw-semibold' : '' }}">
                                    <td class="{{ $total ? '' : 'ps-4' }}">{{ $sign < 0 ? '− ' : '' }}{{ $label }}</td>
                                    <td class="text-end">{{ $sign < 0 && $pnl[$key] ? '('.money($pnl[$key]).')' : money($pnl[$key]) }}</td>
                                    <td class="text-end text-muted">{{ $sign < 0 && $prev[$key] ? '('.money($prev[$key]).')' : money($prev[$key]) }}</td>
                                    <td class="text-end">{!! $delta($pnl[$key], $prev[$key], $goodUp) !!}</td>
                                </tr>
                            @endforeach
                            <tr><td class="ps-4 text-muted small" colspan="4">Gross margin {{ $pnl['gross_margin'] }}% (previous {{ $prev['gross_margin'] }}%)</td></tr>
                            <tr>
                                <td class="ps-4">− Operating expenses</td>
                                <td class="text-end">{{ $pnl['expenses_total'] ? '('.money($pnl['expenses_total']).')' : money(0) }}</td>
                                <td class="text-end text-muted">{{ $prev['expenses_total'] ? '('.money($prev['expenses_total']).')' : money(0) }}</td>
                                <td class="text-end">{!! $delta($pnl['expenses_total'], $prev['expenses_total'], false) !!}</td>
                            </tr>
                            @foreach ($pnl['expenses'] as $expense)
                                <tr class="small text-muted"><td class="ps-5">{{ $expense['category'] }}</td><td class="text-end">{{ money($expense['total']) }}</td><td colspan="2"></td></tr>
                            @endforeach
                            <tr>
                                <td class="ps-4">± Inventory adjustments <i class="ri-information-line text-muted" title="Stock count corrections at cost (negative = shrinkage / write-offs)"></i></td>
                                <td class="text-end {{ $pnl['adjustments'] < 0 ? 'text-danger' : '' }}">{{ money($pnl['adjustments']) }}</td>
                                <td class="text-end text-muted">{{ money($prev['adjustments']) }}</td>
                                <td></td>
                            </tr>
                            <tr class="fw-bold fs-6 {{ $pnl['net_profit'] >= 0 ? 'table-success' : 'table-danger' }}">
                                <td>Net profit</td>
                                <td class="text-end">{{ money($pnl['net_profit']) }}</td>
                                <td class="text-end">{{ money($prev['net_profit']) }}</td>
                                <td class="text-end">{!! $delta($pnl['net_profit'], $prev['net_profit']) !!}</td>
                            </tr>
                        </tbody>
                    </table>
                </div>
                <div class="card-footer small text-muted d-flex flex-wrap gap-3">
                    <span>Sales tax collected: <b class="text-body">{{ money($pnl['tax']) }}</b> (not income)</span>
                    <span>Avg. invoice: <b class="text-body">{{ money($pnl['avg_invoice']) }}</b></span>
                    <span>Returns: <b class="text-body">{{ $pnl['returns_count'] }}</b></span>
                </div>
            </div>
        </div>

        <div class="col-xl-5">
            <div class="card mb-3">
                <div class="card-header"><h6 class="card-title mb-0">Where the money went</h6></div>
                <div class="card-body">
                    @php
                        $base = max($pnl['net_revenue'], 0.01);
                        $bars = [
                            ['Cost of goods', $pnl['cogs'], 'warning'],
                            ['Expenses', $pnl['expenses_total'], 'danger'],
                            ['Net profit', max(0, $pnl['net_profit']), 'success'],
                        ];
                    @endphp
                    @if ($pnl['net_revenue'] > 0)
                        <div class="progress mb-3" style="height: 14px">
                            @foreach ($bars as [$label, $value, $color])
                                <div class="progress-bar bg-{{ $color }}" style="width: {{ min(100, max(0, $value / $base * 100)) }}%" title="{{ $label }}"></div>
                            @endforeach
                        </div>
                        @foreach ($bars as [$label, $value, $color])
                            <div class="d-flex justify-content-between small py-1">
                                <span><span class="color-dot bg-{{ $color }} me-2"></span>{{ $label }}</span>
                                <span class="tabular-nums">{{ money($value) }} <span class="text-muted">({{ round($value / $base * 100, 1) }}%)</span></span>
                            </div>
                        @endforeach
                        @if ($pnl['net_profit'] < 0)
                            <div class="alert alert-danger small mt-2 mb-0">Loss of {{ money(abs($pnl['net_profit'])) }} in this period.</div>
                        @endif
                    @else
                        <x-empty-state icon="ri-pie-chart-2-line" title="No revenue in this period" />
                    @endif
                </div>
            </div>

            <div class="card mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Revenue mix</h6></div>
                <ul class="list-group list-group-flush small tabular-nums">
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Gross sales at list price</span><span>{{ money($pnl['gross_sales']) }}</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Discounts</span><span>{{ $pnl['gross_sales'] > 0 ? round($pnl['discounts'] / $pnl['gross_sales'] * 100, 1) : 0 }}% of list</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Returns</span><span>{{ $pnl['net_sales'] > 0 ? round($pnl['returns'] / $pnl['net_sales'] * 100, 1) : 0 }}% of sales</span></li>
                    <li class="list-group-item d-flex justify-content-between"><span class="text-muted">Pieces sold</span><span>{{ number_format($pnl['items_sold']) }}</span></li>
                </ul>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0">12-month trend</h6>
            <small class="text-muted">{{ $branchLabel }}</small>
        </div>
        <div class="card-body">
            <div wire:key="trend-{{ md5(json_encode($trend)) }}">
                <div wire:ignore x-data x-init="SGMS.chart($el, {
                        money: true,
                        colors: ['primary', 'info', 'success', 'warning'],
                        chart: { type: 'line', height: 330, stacked: false },
                        stroke: { width: [0, 0, 3, 3], curve: 'smooth' },
                        plotOptions: { bar: { columnWidth: '55%', borderRadius: 3 } },
                        series: [
                            { name: 'Net revenue', type: 'column', data: @js(array_column($trend, 'revenue')) },
                            { name: 'Gross profit', type: 'column', data: @js(array_column($trend, 'gross_profit')) },
                            { name: 'Net profit', type: 'line', data: @js(array_column($trend, 'net_profit')) },
                            { name: 'Expenses', type: 'line', data: @js(array_column($trend, 'expenses')) },
                        ],
                        xaxis: { categories: @js(array_column($trend, 'month')) },
                    })" style="min-height: 330px"></div>
            </div>
        </div>
        <div class="table-responsive">
            <table class="table table-sm mb-0 tabular-nums">
                <thead class="table-light"><tr><th>Month</th><th class="text-end">Revenue</th><th class="text-end">COGS</th><th class="text-end">Gross profit</th><th class="text-end">Expenses</th><th class="text-end">Net profit</th></tr></thead>
                <tbody>
                    @foreach (array_reverse($trend) as $row)
                        <tr>
                            <td>{{ $row['month'] }}</td>
                            <td class="text-end">{{ money($row['revenue']) }}</td>
                            <td class="text-end text-muted">{{ money($row['cogs']) }}</td>
                            <td class="text-end">{{ money($row['gross_profit']) }}</td>
                            <td class="text-end text-muted">{{ money($row['expenses']) }}</td>
                            <td class="text-end fw-semibold {{ $row['net_profit'] < 0 ? 'text-danger' : '' }}">{{ money($row['net_profit']) }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>
