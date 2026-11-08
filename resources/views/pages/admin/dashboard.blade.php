<?php

use App\Models\Customer;
use App\Models\Product;
use App\Models\Sale;
use App\Models\Tenant;
use App\Models\User;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.admin')] #[Title('Platform Dashboard')] class extends Component {
    public function with(): array
    {
        // No tenant context here: tenant scopes are inactive, so totals span the platform.
        $tenantCounts = Tenant::query()
            ->selectRaw("COUNT(*) as total, SUM(status = 'active') as active, SUM(status = 'suspended') as suspended, SUM(trial_ends_at > NOW()) as trial")
            ->first();

        $monthStart = now()->startOfMonth();
        $days = collect(range(13, 0))->map(fn ($d) => today()->subDays($d)->toDateString());

        $daily = Sale::query()
            ->where('sale_date', '>=', today()->subDays(13))
            ->selectRaw('DATE(sale_date) as day, SUM(total_amount) as total, COUNT(*) as orders')
            ->groupBy('day')->pluck('total', 'day');

        $topShops = Sale::query()
            ->where('sale_date', '>=', $monthStart)
            ->select('tenant_id', DB::raw('SUM(total_amount) as revenue'), DB::raw('COUNT(*) as orders'))
            ->groupBy('tenant_id')->orderByDesc('revenue')->limit(5)
            ->with('tenant:id,name,slug,status')->get();

        return [
            'counts' => $tenantCounts,
            'users' => User::query()->count(),
            'customers' => Customer::query()->count(),
            'products' => Product::query()->count(),
            'monthSales' => (float) Sale::query()->where('sale_date', '>=', $monthStart)->sum('total_amount'),
            'monthOrders' => Sale::query()->where('sale_date', '>=', $monthStart)->count(),
            'chartLabels' => $days->map(fn ($d) => \Illuminate\Support\Carbon::parse($d)->format('d M'))->values(),
            'chartData' => $days->map(fn ($d) => round((float) ($daily[$d] ?? 0), 2))->values(),
            'topShops' => $topShops,
            'recentShops' => Tenant::query()->withCount(['branches', 'users'])->latest()->limit(6)->get(),
        ];
    }
}; ?>

<div>
    <x-page-header title="Platform overview" subtitle="All shops on the SGMS platform at a glance.">
        <x-slot:actions>
            <a href="{{ route('admin.shops.index', ['create' => 1]) }}" class="btn btn-primary" wire:navigate><i class="ri-add-line me-1"></i>New shop</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-stat-card label="Total shops" :value="number_format($counts->total)" icon="ri-store-3-line" color="primary" :href="route('admin.shops.index')" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Active shops" :value="number_format($counts->active)" icon="ri-checkbox-circle-line" color="success" :hint="$counts->trial.' on trial'" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Suspended" :value="number_format($counts->suspended)" icon="ri-forbid-2-line" color="danger" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="Sales this month" :value="'Rs. '.number_format($monthSales)" icon="ri-line-chart-line" color="info" :hint="number_format($monthOrders).' invoices across all shops'" /></div>
    </div>

    <div class="row g-3">
        <div class="col-xl-8">
            <div class="card h-100 mb-0">
                <div class="card-header d-flex align-items-center justify-content-between">
                    <h6 class="card-title mb-0">Platform sales — last 14 days</h6>
                    <span class="text-muted small">{{ number_format($users) }} staff · {{ number_format($customers) }} customers · {{ number_format($products) }} products</span>
                </div>
                <div class="card-body">
                    <div id="platform-sales-chart" wire:ignore style="min-height: 300px"></div>
                </div>
            </div>
        </div>
        <div class="col-xl-4">
            <div class="card h-100 mb-0">
                <div class="card-header"><h6 class="card-title mb-0">Top shops this month</h6></div>
                <div class="card-body p-0">
                    <ul class="list-group list-group-flush">
                        @forelse ($topShops as $row)
                            <li class="list-group-item d-flex align-items-center gap-3 py-3">
                                <span class="avatar avatar-sm avatar-item avatar-title bg-primary-subtle text-primary rounded-2 fw-semibold">{{ mb_substr($row->tenant?->name ?? '?', 0, 1) }}</span>
                                <div class="flex-grow-1 min-w-0">
                                    <a href="{{ route('admin.shops.show', $row->tenant_id) }}" class="fw-semibold text-body text-truncate d-block" wire:navigate>{{ $row->tenant?->name }}</a>
                                    <small class="text-muted">{{ $row->orders }} invoices</small>
                                </div>
                                <span class="fw-semibold tabular-nums">Rs. {{ number_format($row->revenue) }}</span>
                            </li>
                        @empty
                            <li class="list-group-item"><x-empty-state icon="ri-bar-chart-line" title="No sales yet this month" /></li>
                        @endforelse
                    </ul>
                </div>
            </div>
        </div>
    </div>

    <div class="card mt-3">
        <div class="card-header d-flex justify-content-between align-items-center">
            <h6 class="card-title mb-0">Recently created shops</h6>
            <a href="{{ route('admin.shops.index') }}" class="btn btn-sm btn-light-primary" wire:navigate>View all</a>
        </div>
        <div class="table-responsive">
            <table class="table table-hover mb-0 table-stack">
                <thead class="table-light"><tr><th>Shop</th><th>Owner</th><th>Plan</th><th>Branches</th><th>Staff</th><th>Status</th><th>Created</th></tr></thead>
                <tbody>
                    @foreach ($recentShops as $shop)
                        <tr>
                            <td data-label="Shop"><a href="{{ route('admin.shops.show', $shop) }}" class="fw-semibold" wire:navigate>{{ $shop->name }}</a><div class="text-muted small">/{{ $shop->slug }}</div></td>
                            <td data-label="Owner">{{ $shop->owner_email }}</td>
                            <td data-label="Plan">{{ \App\Models\Tenant::PLANS[$shop->plan] ?? ucfirst($shop->plan) }}</td>
                            <td data-label="Branches">{{ $shop->branches_count }}</td>
                            <td data-label="Staff">{{ $shop->users_count }}</td>
                            <td data-label="Status"><x-status-badge :status="$shop->status" /></td>
                            <td data-label="Created">{{ $shop->created_at->format('d M Y') }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    </div>
</div>

@script
<script>
    const el = document.getElementById('platform-sales-chart');
    if (el && window.ApexCharts) {
        new ApexCharts(el, {
            chart: { type: 'area', height: 300, toolbar: { show: false }, fontFamily: 'inherit' },
            series: [{ name: 'Sales', data: @js($chartData) }],
            xaxis: { categories: @js($chartLabels) },
            yaxis: { labels: { formatter: (v) => 'Rs. ' + Math.round(v).toLocaleString() } },
            dataLabels: { enabled: false },
            stroke: { curve: 'smooth', width: 3 },
            colors: [SGMS.color('primary')],
            fill: { type: 'gradient', gradient: { opacityFrom: .35, opacityTo: .02 } },
            grid: { borderColor: 'rgba(148,163,184,.2)' },
        }).render();
    }
</script>
@endscript
