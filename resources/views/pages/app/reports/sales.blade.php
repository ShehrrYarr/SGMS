<?php

use App\Livewire\Concerns\WithReportFilters;
use App\Services\ReportService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Sales Analysis')] class extends Component {
    use WithReportFilters;

    #[Url(except: 'category')]
    public string $group = 'category';

    #[Url(except: 'revenue')]
    public string $sort = 'revenue';

    /** ?group= and ?sort= come from the URL: fall back to the defaults for unknown values. */
    public function mount(): void
    {
        if (! array_key_exists($this->group, ReportService::SALES_GROUPS)) {
            $this->group = 'category';
        }

        $this->sortBy($this->sort);
    }

    public function updatedGroup(): void
    {
        $this->mount();
    }

    public function updatedSort(): void
    {
        $this->sortBy($this->sort);
    }

    public function setGroup(string $group): void
    {
        $this->group = array_key_exists($group, ReportService::SALES_GROUPS) ? $group : 'category';
        $this->sort = $this->group === 'day' ? 'key' : 'revenue';
    }

    public function sortBy(string $column): void
    {
        $this->sort = in_array($column, ['key', 'label', 'invoices', 'qty', 'revenue', 'profit', 'margin'], true) ? $column : 'revenue';
    }

    protected function rows(ReportService $reports)
    {
        [$from, $to] = $this->period();
        $group = array_key_exists($this->group, ReportService::SALES_GROUPS) ? $this->group : 'category';
        $rows = $reports->salesBreakdown($from, $to, $this->reportBranchIds(), $group);

        return match ($this->sort) {
            'key' => $rows,
            'label' => $rows->sortBy('label', SORT_NATURAL | SORT_FLAG_CASE)->values(),
            default => $rows->sortByDesc($this->sort)->values(),
        };
    }

    public function export(ReportService $reports)
    {
        [$from, $to] = $this->period();
        $rows = $this->rows($reports);
        $label = ReportService::SALES_GROUPS[$this->group] ?? 'Group';

        return response()->streamDownload(function () use ($rows, $label) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [$label, 'Invoices', 'Qty', 'Net revenue', 'Cost', 'Gross profit', 'Margin %', 'Share %']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['label'], $r['invoices'], $r['qty'], $r['revenue'], $r['cost'], $r['profit'], $r['margin'], $r['share']]);
            }
            fclose($out);
        }, "sales-by-{$this->group}-{$from->toDateString()}-to-{$to->toDateString()}.csv", ['Content-Type' => 'text/csv']);
    }

    public function with(ReportService $reports): array
    {
        [$from, $to] = $this->period();
        $rows = $this->rows($reports);
        $isMoneyOnly = $this->group === 'payment';
        // Totals from unrounded values (per-row rounding would drift by cents).
        $revenue = round((float) $rows->sum('revenue_exact'), 2);
        $profit = round($revenue - (float) $rows->sum('cost'), 2);
        $invoices = $isMoneyOnly ? null : $reports->profitAndLoss($from, $to, $this->reportBranchIds())['invoices'];

        $chartRows = $this->group === 'day' ? $rows->sortBy('key')->values() : $rows->sortByDesc('revenue')->take(12)->values();

        return $this->filterViewData() + [
            'groups' => ReportService::SALES_GROUPS,
            'rows' => $rows,
            'isMoneyOnly' => $isMoneyOnly,
            'summary' => [
                'revenue' => $revenue,
                'profit' => $profit,
                'margin' => $revenue > 0 ? round($profit / $revenue * 100, 1) : 0,
                'qty' => (int) $rows->sum('qty'),
                'invoices' => $invoices,
                'basket' => $invoices ? $revenue / $invoices : 0,
            ],
            'chart' => [
                'labels' => $chartRows->map(fn ($r) => $this->group === 'day' ? \Illuminate\Support\Carbon::parse($r['label'])->format('d M') : \Illuminate\Support\Str::limit($r['label'], 22))->all(),
                'revenue' => $chartRows->pluck('revenue')->all(),
                'profit' => $chartRows->pluck('profit')->map(fn ($v) => $v ?? 0)->all(),
            ],
        ];
    }
}; ?>

<div>
    @include('partials.report-toolbar', ['actions' => '<button type="button" class="btn btn-light-success" wire:click="export"><i class="ri-file-excel-2-line me-1"></i>Export CSV</button>'])

    <div class="card mb-3 no-print">
        <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
            <span class="small text-muted me-1">Group by</span>
            @foreach ($groups as $key => $label)
                <button type="button" class="chip chip-sm {{ $group === $key ? 'active' : '' }}" wire:click="setGroup('{{ $key }}')">{{ $label }}</button>
            @endforeach
        </div>
    </div>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-stat-card :label="$isMoneyOnly ? 'Collected' : 'Net sales'" :value="money($summary['revenue'])" icon="ri-money-dollar-circle-line" :hint="$isMoneyOnly ? 'Payments received in the period' : 'Excl. tax, after discounts'" /></div>
        @unless ($isMoneyOnly)
            <div class="col-6 col-xl-3"><x-stat-card label="Gross profit" :value="money($summary['profit'])" icon="ri-funds-line" color="info" :hint="$summary['margin'].'% margin'" /></div>
            <div class="col-6 col-xl-3"><x-stat-card label="Pieces sold" :value="number_format($summary['qty'])" icon="ri-t-shirt-2-line" color="warning" /></div>
            <div class="col-6 col-xl-3"><x-stat-card label="Invoices" :value="number_format($summary['invoices'])" icon="ri-file-list-3-line" color="success" :hint="'Avg. basket '.money($summary['basket'])" /></div>
        @endunless
    </div>

    <div class="card">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0">{{ $isMoneyOnly ? 'Collections' : 'Net sales' }} by {{ strtolower($groups[$group]) }}</h6>
            @if ($group !== 'day' && $rows->count() > 12)<small class="text-muted">Top 12 shown in chart</small>@endif
        </div>
        <div class="card-body">
            @if ($rows->isEmpty())
                <x-empty-state icon="ri-bar-chart-grouped-line" title="No sales in this period" />
            @else
                <div wire:key="sales-chart-{{ md5(json_encode($chart).$group) }}">
                    <div wire:ignore x-data x-init="SGMS.chart($el, {
                            money: true,
                            colors: ['primary', 'success'],
                            chart: { type: @js($group === 'day' ? 'area' : 'bar'), height: 320 },
                            plotOptions: { bar: { horizontal: @js($group !== 'day' && count($chart['labels']) > 6), borderRadius: 3, columnWidth: '55%', barHeight: '65%' } },
                            stroke: { curve: 'smooth', width: @js($group === 'day' ? 3 : 0) },
                            fill: { opacity: @js($group === 'day' ? 0.25 : 1) },
                            series: @js($isMoneyOnly ? [['name' => 'Collected', 'data' => $chart['revenue']]] : [['name' => 'Net sales', 'data' => $chart['revenue']], ['name' => 'Gross profit', 'data' => $chart['profit']]]),
                            xaxis: { categories: @js($chart['labels']) },
                        })" style="min-height: 320px"></div>
                </div>
            @endif
        </div>
        @if ($rows->isNotEmpty())
            <div class="table-responsive">
                <table class="table table-hover mb-0 table-stack tabular-nums">
                    <thead class="table-light">
                        <tr>
                            <th class="th-sortable" wire:click="sortBy('{{ $group === 'day' ? 'key' : 'label' }}')">{{ $groups[$group] }}</th>
                            <th class="text-end th-sortable" wire:click="sortBy('invoices')">Invoices</th>
                            @unless ($isMoneyOnly)<th class="text-end th-sortable" wire:click="sortBy('qty')">Qty</th>@endunless
                            <th class="text-end th-sortable" wire:click="sortBy('revenue')">{{ $isMoneyOnly ? 'Collected' : 'Net sales' }} @if ($sort === 'revenue')<i class="ri-arrow-down-line text-primary"></i>@endif</th>
                            @unless ($isMoneyOnly)
                                <th class="text-end">Cost</th>
                                <th class="text-end th-sortable" wire:click="sortBy('profit')">Profit @if ($sort === 'profit')<i class="ri-arrow-down-line text-primary"></i>@endif</th>
                                <th class="text-end th-sortable" wire:click="sortBy('margin')">Margin @if ($sort === 'margin')<i class="ri-arrow-down-line text-primary"></i>@endif</th>
                            @endunless
                            <th style="width: 140px">Share</th>
                        </tr>
                    </thead>
                    <tbody>
                        @foreach ($rows as $row)
                            <tr>
                                <td data-label="{{ $groups[$group] }}">
                                    @if ($group === 'color')<span class="color-dot me-1" style="background: {{ $row['extra'] ?? '#e5e7eb' }}"></span>@endif
                                    <span class="fw-medium">{{ $group === 'day' ? \Illuminate\Support\Carbon::parse($row['label'])->format('D, d M Y') : $row['label'] }}</span>
                                    @if (in_array($group, ['product', 'customer', 'branch'], true) && $row['extra'])<div class="small text-muted">{{ $row['extra'] }}</div>@endif
                                    @if ($group === 'staff' && $row['extra'])<div class="small text-muted">{{ ucfirst($row['extra']) }}</div>@endif
                                </td>
                                <td data-label="Invoices" class="text-end">{{ number_format($row['invoices']) }}</td>
                                @unless ($isMoneyOnly)<td data-label="Qty" class="text-end">{{ number_format($row['qty']) }}</td>@endunless
                                <td data-label="Revenue" class="text-end fw-semibold">{{ money($row['revenue']) }}</td>
                                @unless ($isMoneyOnly)
                                    <td data-label="Cost" class="text-end text-muted">{{ money($row['cost']) }}</td>
                                    <td data-label="Profit" class="text-end {{ $row['profit'] < 0 ? 'text-danger' : '' }}">{{ money($row['profit']) }}</td>
                                    <td data-label="Margin" class="text-end"><span class="badge {{ $row['margin'] >= 40 ? 'bg-success-subtle text-success' : ($row['margin'] >= 20 ? 'bg-warning-subtle text-warning' : 'bg-danger-subtle text-danger') }}">{{ $row['margin'] }}%</span></td>
                                @endunless
                                <td data-label="Share">
                                    <div class="d-flex align-items-center gap-2"><div class="share-bar flex-grow-1"><span style="width: {{ min(100, $row['share']) }}%"></span></div><small class="text-muted">{{ $row['share'] }}%</small></div>
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                    <tfoot class="table-light fw-semibold">
                        <tr>
                            <td>Total</td>
                            <td class="text-end">{{ $isMoneyOnly ? '' : number_format($summary['invoices']) }}</td>
                            @unless ($isMoneyOnly)<td class="text-end">{{ number_format($summary['qty']) }}</td>@endunless
                            <td class="text-end">{{ money($summary['revenue']) }}</td>
                            @unless ($isMoneyOnly)
                                <td class="text-end">{{ money($rows->sum('cost')) }}</td>
                                <td class="text-end">{{ money($summary['profit']) }}</td>
                                <td class="text-end">{{ $summary['margin'] }}%</td>
                            @endunless
                            <td></td>
                        </tr>
                    </tfoot>
                </table>
            </div>
            <div class="card-footer small text-muted">
                @if ($isMoneyOnly)
                    Money received on sales by account, including later payments on credit invoices. Exchange credit is not cash.
                @else
                    Net sales exclude tax and include invoice discounts spread over lines. Figures are before returns (see Profit & loss). Profit uses the cost recorded at the time of sale.
                @endif
            </div>
        @endif
    </div>
</div>
