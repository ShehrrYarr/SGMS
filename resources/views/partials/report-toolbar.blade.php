{{-- Report tabs + period / branch filters. Expects $ranges, $branchOptions, $periodLabel, $branchLabel; optional $actions. --}}
<div class="hstack flex-wrap gap-3 mb-3">
    <div class="flex-grow-1 min-w-0">
        <h4 class="mb-1 fw-semibold">Reports</h4>
        <div class="text-muted small"><i class="ri-calendar-line me-1"></i>{{ $periodLabel }} · <i class="ri-store-2-line me-1"></i>{{ $branchLabel }}</div>
    </div>
    <div class="d-flex flex-wrap gap-2 no-print">
        {!! $actions ?? '' !!}
        <button type="button" class="btn btn-light-secondary" onclick="window.print()"><i class="ri-printer-line me-1"></i>Print</button>
    </div>
</div>

<div class="card mb-3 no-print">
    <div class="card-body py-2 d-flex flex-wrap gap-2 align-items-center">
        <ul class="nav nav-pills nav-tabs-scroll me-auto">
            @foreach (['app.reports.profit-loss' => ['Profit & loss', 'ri-funds-line'], 'app.reports.sales' => ['Sales analysis', 'ri-bar-chart-grouped-line'], 'app.reports.stock' => ['Stock valuation', 'ri-stack-line']] as $route => [$label, $icon])
                <li class="nav-item">
                    <a href="{{ route($route, array_filter(['range' => $range !== 'this_month' ? $range : null, 'from' => $from ?: null, 'to' => $to ?: null, 'branch' => $branch ?: null])) }}"
                       class="nav-link {{ $reportRoute === $route ? 'active' : '' }}" wire:navigate><i class="{{ $icon }} me-1"></i>{{ $label }}</a>
                </li>
            @endforeach
        </ul>
        <div class="d-flex flex-wrap gap-2 align-items-center">
            <select wire:model.live="range" class="form-select form-select-sm" style="width: auto">
                @foreach ($ranges as $key => $label)<option value="{{ $key }}">{{ $label }}</option>@endforeach
            </select>
            @if ($range === 'custom')
                <input type="date" wire:model.live.debounce.500ms="from" class="form-control form-control-sm" style="width: auto" title="From">
                <input type="date" wire:model.live.debounce.500ms="to" class="form-control form-control-sm" style="width: auto" title="To">
            @endif
            @if ($branchOptions->count() > 1)
                <select wire:model.live="branch" class="form-select form-select-sm" style="width: auto">
                    <option value="">Current branch view</option>
                    <option value="all">All branches</option>
                    @foreach ($branchOptions as $b)<option value="{{ $b->id }}">{{ $b->name }}</option>@endforeach
                </select>
            @endif
            <span wire:loading class="spinner-border spinner-border-sm text-primary"></span>
        </div>
    </div>
</div>
