<?php

use App\Livewire\Concerns\InteractsWithUi;
use App\Livewire\Concerns\WithReportFilters;
use App\Services\ReportService;
use App\Services\StockSnapshotService;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Stock Valuation')] class extends Component {
    use InteractsWithUi, WithReportFilters;

    #[Url(except: 'category')]
    public string $by = 'category';

    #[Url(except: 60)]
    public int $agedDays = 60;

    public function mount(StockSnapshotService $snapshots): void
    {
        $this->range = request()->query('range', 'last_30');

        // Keep today's point current (cheap: one aggregate over inventories).
        $snapshots->capture();
    }

    /** Rebuild missing days in the selected period from stock movements. */
    public function rebuild(StockSnapshotService $snapshots): void
    {
        abort_unless(auth('web')->user()->canManage(), 403);
        [$from, $to] = $this->period();

        $days = $snapshots->backfill($from->copy()->max(today()->subDays(730)), $to);
        $this->toast("Rebuilt {$days} day(s) of stock history.");
    }

    public function export(ReportService $reports)
    {
        $rows = $reports->stockValuation($this->reportBranchIds(), $this->by === 'branch' ? 'branch' : 'category');

        return response()->streamDownload(function () use ($rows) {
            $out = fopen('php://output', 'w');
            fputcsv($out, [$this->by === 'branch' ? 'Branch' : 'Category', 'Variants', 'Units', 'Cost value', 'Retail value', 'Markup %', 'Share %']);
            foreach ($rows as $r) {
                fputcsv($out, [$r['label'], $r['variants'], $r['units'], $r['cost_value'], $r['retail_value'], $r['markup'], $r['share']]);
            }
            fclose($out);
        }, 'stock-valuation-'.today()->toDateString().'.csv', ['Content-Type' => 'text/csv']);
    }

    public function with(ReportService $reports): array
    {
        [$from, $to] = $this->period();
        $branchIds = $this->reportBranchIds();
        $valuation = $reports->stockValuation($branchIds, $this->by === 'branch' ? 'branch' : 'category');
        $history = $reports->stockHistory($from, $to, $branchIds);
        $first = $history[0] ?? null;
        $last = end($history) ?: null;
        $cost = (float) $valuation->sum('cost_value');
        $retail = (float) $valuation->sum('retail_value');

        return $this->filterViewData() + [
            'valuation' => $valuation,
            'history' => $history,
            'flow' => $reports->stockFlow($from, $to, $branchIds),
            'aged' => $reports->agedStock($branchIds, max(15, min(365, $this->agedDays))),
            'missing' => $reports->missingSnapshotDays($from, $to, $branchIds),
            'reconstructed' => collect($history)->contains('reconstructed', true),
            'totals' => [
                'units' => (int) $valuation->sum('units'),
                'cost' => $cost,
                'retail' => $retail,
                'potential' => $retail - $cost,
                'markup' => $cost > 0 ? round(($retail - $cost) / $cost * 100, 1) : 0,
                'change' => $first && $last ? $last['cost_value'] - $first['cost_value'] : null,
            ],
            'singleBranch' => count($branchIds) === 1,
        ];
    }
}; ?>

<div>
    @include('partials.report-toolbar', ['actions' => '<button type="button" class="btn btn-light-success" wire:click="export"><i class="ri-file-excel-2-line me-1"></i>Export CSV</button>'])

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-stat-card label="Units in stock" :value="number_format($totals['units'])" icon="ri-stack-line" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Value at cost" :value="money($totals['cost'])" icon="ri-money-dollar-box-line" color="warning" :hint="$totals['change'] !== null ? (($totals['change'] >= 0 ? '+' : '').money($totals['change']).' over period') : null" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Value at retail" :value="money($totals['retail'])" icon="ri-price-tag-3-line" color="success" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Potential gross profit" :value="money($totals['potential'])" icon="ri-funds-line" color="info" :hint="$totals['markup'].'% markup on cost'" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card mb-0 h-100">
                <div class="card-header d-flex flex-wrap gap-2 justify-content-between align-items-center">
                    <h6 class="card-title mb-0">Stock value over time</h6>
                    @if ($missing > 0 && auth('web')->user()->canManage())
                        <button class="btn btn-sm btn-light-warning no-print" wire:click="rebuild" wire:loading.attr="disabled" wire:target="rebuild">
                            <i class="ri-history-line me-1"></i>Rebuild {{ $missing }} missing day(s)
                        </button>
                    @endif
                </div>
                <div class="card-body">
                    @if (count($history) < 2)
                        <x-empty-state icon="ri-line-chart-line" title="Not enough history yet" text="A snapshot is saved every night. Use “Rebuild missing days” to reconstruct earlier days from stock movements." />
                    @else
                        <div wire:key="stock-chart-{{ md5(json_encode($history)) }}">
                            <div wire:ignore x-data x-init="SGMS.chart($el, {
                                    money: true,
                                    colors: ['warning', 'success'],
                                    chart: { type: 'area', height: 320 },
                                    stroke: { curve: 'smooth', width: 3 },
                                    fill: { type: 'gradient', gradient: { opacityFrom: .3, opacityTo: .02 } },
                                    series: [
                                        { name: 'Value at cost', data: @js(array_column($history, 'cost_value')) },
                                        { name: 'Value at retail', data: @js(array_column($history, 'retail_value')) },
                                    ],
                                    xaxis: { categories: @js(array_column($history, 'label')) },
                                })" style="min-height: 320px"></div>
                        </div>
                        @if ($reconstructed)
                            <div class="small text-muted mt-2"><i class="ri-information-line"></i> Some days were rebuilt from stock movements and are valued at today's cost. Nightly snapshots keep the cost of each day.</div>
                        @endif
                    @endif
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card mb-0 h-100">
                <div class="card-header"><h6 class="card-title mb-0">Stock flow (units)</h6><small class="text-muted">{{ $periodLabel }}</small></div>
                <div class="card-body tabular-nums">
                    <div class="flow-row fw-semibold"><span>Opening stock</span><span>{{ number_format($flow['opening']) }}</span></div>
                    @foreach ($flow['types'] as $type => $row)
                        @continue(! $singleBranch && in_array($type, ['transfer_in', 'transfer_out'], true) && $row['qty'] === 0)
                        <div class="flow-row">
                            <span class="text-muted">{{ $row['label'] }}</span>
                            <span class="{{ $row['qty'] > 0 ? 'text-success' : ($row['qty'] < 0 ? 'text-danger' : 'text-muted') }}">{{ $row['qty'] > 0 ? '+' : '' }}{{ number_format($row['qty']) }}</span>
                        </div>
                    @endforeach
                    <div class="flow-row fw-bold fs-6"><span>Closing stock</span><span>{{ number_format($flow['closing']) }}</span></div>
                    @unless ($singleBranch)
                        <div class="small text-muted mt-2">Transfers between branches cancel out when all branches are selected.</div>
                    @endunless
                </div>
            </div>
        </div>
    </div>

    <div class="row g-3 mt-0">
        <div class="col-xl-6">
            <div class="card mb-0 h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0">Current valuation</h6>
                    <div class="btn-group btn-group-sm no-print">
                        <button class="btn {{ $by === 'category' ? 'btn-primary' : 'btn-light' }}" wire:click="$set('by', 'category')">By category</button>
                        <button class="btn {{ $by === 'branch' ? 'btn-primary' : 'btn-light' }}" wire:click="$set('by', 'branch')">By branch</button>
                    </div>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-stack tabular-nums">
                        <thead class="table-light"><tr><th>{{ $by === 'branch' ? 'Branch' : 'Category' }}</th><th class="text-end">Units</th><th class="text-end">At cost</th><th class="text-end">At retail</th><th class="text-end">Markup</th><th style="width: 110px">Share</th></tr></thead>
                        <tbody>
                            @forelse ($valuation as $row)
                                <tr>
                                    <td data-label="{{ $by === 'branch' ? 'Branch' : 'Category' }}" class="fw-medium">{{ $row['label'] }}<div class="small text-muted">{{ $row['variants'] }} variants</div></td>
                                    <td data-label="Units" class="text-end">{{ number_format($row['units']) }}</td>
                                    <td data-label="At cost" class="text-end">{{ money($row['cost_value']) }}</td>
                                    <td data-label="At retail" class="text-end text-muted">{{ money($row['retail_value']) }}</td>
                                    <td data-label="Markup" class="text-end">{{ $row['markup'] }}%</td>
                                    <td data-label="Share"><div class="d-flex align-items-center gap-2"><div class="share-bar flex-grow-1"><span style="width: {{ min(100, $row['share']) }}%"></span></div><small class="text-muted">{{ $row['share'] }}%</small></div></td>
                                </tr>
                            @empty
                                <tr><td colspan="6"><x-empty-state icon="ri-stack-line" title="No stock on hand" /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        <div class="col-xl-6">
            <div class="card mb-0 h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <div>
                        <h6 class="card-title mb-0">Aged stock</h6>
                        <small class="text-muted">In stock, not sold for {{ $agedDays }}+ days</small>
                    </div>
                    <select wire:model.live="agedDays" class="form-select form-select-sm no-print" style="width: auto">
                        @foreach ([30, 60, 90, 180] as $d)<option value="{{ $d }}">{{ $d }} days</option>@endforeach
                    </select>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-stack tabular-nums">
                        <thead class="table-light"><tr><th>Item</th><th class="text-end">Units</th><th class="text-end">Value at cost</th><th class="text-end">Last sold</th></tr></thead>
                        <tbody>
                            @forelse ($aged as $row)
                                <tr>
                                    <td data-label="Item"><span class="color-dot me-1" style="background: {{ $row['hex'] ?? '#e5e7eb' }}"></span><span class="fw-medium">{{ $row['name'] }}</span><div class="small text-muted">{{ $row['variant'] }} · {{ $row['sku'] }}</div></td>
                                    <td data-label="Units" class="text-end">{{ $row['units'] }}</td>
                                    <td data-label="Value" class="text-end fw-semibold">{{ money($row['cost_value']) }}</td>
                                    <td data-label="Last sold" class="text-end small">{{ $row['last_sold']?->format('d M Y') ?? 'Never' }}<div class="text-muted">{{ $row['age_days'] }} days</div></td>
                                </tr>
                            @empty
                                <tr><td colspan="4"><x-empty-state icon="ri-checkbox-circle-line" title="No slow-moving stock" text="Everything in stock has sold recently." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
                @if ($aged->isNotEmpty())
                    <div class="card-footer small text-muted">Consider a sale, a transfer to a busier branch, or returning these to the vendor.</div>
                @endif
            </div>
        </div>
    </div>
</div>
