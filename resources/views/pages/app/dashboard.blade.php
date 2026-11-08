<?php

use App\Models\Customer;
use App\Models\Expense;
use App\Models\Inventory;
use App\Models\PaymentAccount;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\Vendor;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Livewire\Attributes\Layout;
use Livewire\Attributes\Title;
use Livewire\Volt\Component;

new #[Layout('layouts.app')] #[Title('Dashboard')] class extends Component {
    public function with(): array
    {
        $user = auth('web')->user();
        $branchIds = tenancy()->branchScopeIds();
        $mine = $user->isCashier() ? $user->id : null;

        $sales = fn () => Sale::query()->whereIn('branch_id', $branchIds)->when($mine, fn ($q) => $q->where('created_by', $mine));
        $today = $sales()->whereDate('sale_date', today())->selectRaw('COALESCE(SUM(total_amount),0) total, COUNT(*) cnt')->first();
        $month = $sales()->where('sale_date', '>=', now()->startOfMonth())->selectRaw('COALESCE(SUM(total_amount),0) total, COUNT(*) cnt, COALESCE(SUM(returned_amount),0) returned')->first();

        $days = collect(range(29, 0))->map(fn ($d) => today()->subDays($d)->toDateString());
        $daily = $sales()->where('sale_date', '>=', today()->subDays(29))
            ->selectRaw('DATE(sale_date) d, SUM(total_amount) t')->groupBy('d')->pluck('t', 'd');

        $data = [
            'user' => $user,
            'today' => $today,
            'month' => $month,
            'chartLabels' => $days->map(fn ($d) => Carbon::parse($d)->format('d M'))->values(),
            'chartData' => $days->map(fn ($d) => round((float) ($daily[$d] ?? 0), 2))->values(),
            'recentSales' => $sales()->with('customer:id,name', 'branch:id,name')->latest('sale_date')->limit(8)->get(),
        ];

        if (! $user->canManage()) {
            return $data;
        }

        $profit = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sales.branch_id', $branchIds)
            ->where('sales.sale_date', '>=', now()->startOfMonth())
            ->selectRaw('COALESCE(SUM(sale_items.total_price - sale_items.cost_price * sale_items.quantity), 0) as gp')
            ->value('gp');

        return $data + [
            'grossProfit' => (float) $profit,
            'expensesMonth' => (float) Expense::query()->whereIn('branch_id', $branchIds)->where('expense_date', '>=', now()->startOfMonth())->sum('amount'),
            'purchasesMonth' => (float) Purchase::query()->whereIn('branch_id', $branchIds)->where('purchase_date', '>=', now()->startOfMonth())->sum('total_lot_cost'),
            'receivable' => (float) Customer::query()->where('current_balance', '>', 0)->sum('current_balance'),
            'payable' => (float) Vendor::query()->where('current_balance', '>', 0)->sum('current_balance'),
            'accounts' => PaymentAccount::query()->active()
                ->where(fn ($q) => $q->whereNull('branch_id')->orWhereIn('branch_id', $branchIds))
                ->orderByRaw("FIELD(account_type, 'cash_drawer', 'bank', 'wallet')")->get(),
            'stockUnits' => (int) Inventory::query()->whereIn('branch_id', $branchIds)->sum('quantity'),
            'lowStock' => Inventory::query()
                ->whereIn('inventories.branch_id', $branchIds)
                ->join('product_variants', 'product_variants.id', '=', 'inventories.product_variant_id')
                ->whereNull('product_variants.deleted_at')
                ->whereColumn('inventories.quantity', '<=', 'product_variants.alert_quantity')
                ->select('inventories.*')
                ->with(['variant.product:id,name', 'variant.color:id,name,hex_code', 'variant.size:id,code', 'branch:id,name'])
                ->orderBy('inventories.quantity')->limit(8)->get(),
            'topProducts' => SaleItem::query()
                ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
                ->join('product_variants', 'product_variants.id', '=', 'sale_items.product_variant_id')
                ->join('products', 'products.id', '=', 'product_variants.product_id')
                ->whereIn('sales.branch_id', $branchIds)
                ->where('sales.sale_date', '>=', now()->startOfMonth())
                ->groupBy('products.id', 'products.name')
                ->select('products.name', DB::raw('SUM(sale_items.quantity) qty'), DB::raw('SUM(sale_items.total_price) revenue'))
                ->orderByDesc('qty')->limit(5)->get(),
        ];
    }
}; ?>

<div>
    <x-page-header :title="'Good '.(now()->hour < 12 ? 'morning' : (now()->hour < 17 ? 'afternoon' : 'evening')).', '.str($user->name)->before(' ')" :subtitle="now()->format('l, d F Y').' · '.(tenancy()->branches()->firstWhere('id', tenancy()->branchId())?->name ?? 'All branches')">
        <x-slot:actions>
            @if ($user->canManage())
                <a href="{{ route('app.reports.profit-loss') }}" class="btn btn-light-info" wire:navigate><i class="ri-funds-line me-1"></i>Reports</a>
                <a href="{{ route('app.purchases.create') }}" class="btn btn-light-primary" wire:navigate><i class="ri-inbox-archive-line me-1"></i>New lot</a>
            @endif
            <a href="{{ route('app.pos') }}" class="btn btn-primary"><i class="ri-shopping-cart-2-line me-1"></i>Open POS</a>
        </x-slot:actions>
    </x-page-header>

    <div class="row g-3 mb-3">
        <div class="col-6 col-xl-3"><x-stat-card label="Today's sales" :value="money($today->total)" icon="ri-money-dollar-circle-line" color="primary" :hint="$today->cnt.' invoices'" :href="route('app.sales.index')" /></div>
        <div class="col-6 col-xl-3"><x-stat-card label="This month" :value="money($month->total)" icon="ri-calendar-check-line" color="success" :hint="$month->cnt.' invoices'.((float) $month->returned ? ' · '.money($month->returned).' returned' : '')" /></div>
        @if ($user->canManage())
            <div class="col-6 col-xl-3"><x-stat-card label="Gross profit (month)" :value="money($grossProfit)" icon="ri-funds-line" color="info" :hint="'Expenses '.money($expensesMonth)" /></div>
            <div class="col-6 col-xl-3"><x-stat-card label="Stock on hand" :value="number_format($stockUnits).' pcs'" icon="ri-stack-line" color="warning" :hint="'Lots this month '.money($purchasesMonth)" :href="route('app.inventory.index')" /></div>
        @endif
    </div>

    @if ($user->canManage())
        <div class="row g-3 mb-3">
            <div class="col-md-6 col-xl-3"><x-stat-card label="Receivable from customers" :value="money($receivable)" icon="ri-user-received-2-line" color="danger" :href="route('app.customers.index', ['balance' => 'due'])" /></div>
            <div class="col-md-6 col-xl-3"><x-stat-card label="Payable to vendors" :value="money($payable)" icon="ri-truck-line" color="secondary" :href="route('app.vendors.index')" /></div>
            <div class="col-xl-6">
                <div class="card mb-0 h-100">
                    <div class="card-body p-3">
                        <div class="d-flex justify-content-between align-items-center mb-2">
                            <h6 class="mb-0 fs-13 text-muted">Account balances</h6>
                            <a href="{{ route('app.accounts.index') }}" class="small" wire:navigate>Manage</a>
                        </div>
                        <div class="d-flex gap-2 overflow-auto pb-1">
                            @foreach ($accounts as $account)
                                <a href="{{ route('app.accounts.show', $account) }}" class="border rounded-3 p-2 px-3 text-body flex-shrink-0" style="min-width: 150px" wire:navigate>
                                    <div class="small text-muted text-truncate"><i class="{{ $account->icon }} me-1"></i>{{ $account->account_name }}</div>
                                    <div class="fw-semibold tabular-nums">{{ money($account->current_balance) }}</div>
                                </a>
                            @endforeach
                        </div>
                    </div>
                </div>
            </div>
        </div>
    @endif

    <div class="row g-3">
        <div class="{{ $user->canManage() ? 'col-xl-8' : 'col-12' }}">
            <div class="card mb-0 h-100">
                <div class="card-header"><h6 class="card-title mb-0">Sales — last 30 days</h6></div>
                <div class="card-body"><div id="sales-chart" wire:ignore style="min-height: 300px"></div></div>
            </div>
        </div>
        @if ($user->canManage())
            <div class="col-xl-4">
                <div class="card mb-0 h-100">
                    <div class="card-header d-flex justify-content-between align-items-center">
                        <h6 class="card-title mb-0">Low stock alerts</h6>
                        <a href="{{ route('app.inventory.index', ['filter' => 'low']) }}" class="small" wire:navigate>View all</a>
                    </div>
                    <ul class="list-group list-group-flush">
                        @forelse ($lowStock as $row)
                            <li class="list-group-item d-flex align-items-center gap-2">
                                <span class="color-dot" style="background: {{ $row->variant->color?->hex_code ?? '#e5e7eb' }}"></span>
                                <div class="flex-grow-1 min-w-0">
                                    <div class="fw-medium text-truncate">{{ $row->variant->product->name }}</div>
                                    <small class="text-muted">{{ $row->variant->variant_name }} · {{ $row->branch->name }}</small>
                                </div>
                                <span class="badge {{ $row->quantity <= 0 ? 'bg-danger' : 'bg-warning-subtle text-warning' }}">{{ $row->quantity }} left</span>
                            </li>
                        @empty
                            <li class="list-group-item"><x-empty-state icon="ri-checkbox-circle-line" title="All stock levels healthy" /></li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endif
    </div>

    <div class="row g-3 mt-0">
        <div class="{{ $user->canManage() ? 'col-xl-8' : 'col-12' }}">
            <div class="card mb-0 h-100">
                <div class="card-header d-flex justify-content-between align-items-center">
                    <h6 class="card-title mb-0">Recent sales</h6>
                    <a href="{{ route('app.sales.index') }}" class="btn btn-sm btn-light-primary" wire:navigate>All sales</a>
                </div>
                <div class="table-responsive">
                    <table class="table table-hover mb-0 table-stack">
                        <thead class="table-light"><tr><th>Invoice</th><th>Customer</th><th>Branch</th><th>Date</th><th class="text-end">Total</th><th>Status</th></tr></thead>
                        <tbody>
                            @forelse ($recentSales as $sale)
                                <tr>
                                    <td data-label="Invoice"><a href="{{ route('app.sales.show', $sale) }}" class="fw-semibold" wire:navigate>{{ $sale->invoice_number }}</a></td>
                                    <td data-label="Customer">{{ $sale->customer_name }}</td>
                                    <td data-label="Branch">{{ $sale->branch->name }}</td>
                                    <td data-label="Date" class="small">{{ $sale->sale_date->format('d M, h:i A') }}</td>
                                    <td data-label="Total" class="text-end fw-semibold tabular-nums">{{ money($sale->total_amount) }}</td>
                                    <td data-label="Status"><x-status-badge :status="$sale->payment_status" /></td>
                                </tr>
                            @empty
                                <tr><td colspan="6"><x-empty-state icon="ri-shopping-bag-3-line" title="No sales yet" text="Sales made at the POS appear here." /></td></tr>
                            @endforelse
                        </tbody>
                    </table>
                </div>
            </div>
        </div>
        @if ($user->canManage())
            <div class="col-xl-4">
                <div class="card mb-0 h-100">
                    <div class="card-header"><h6 class="card-title mb-0">Best sellers this month</h6></div>
                    <ul class="list-group list-group-flush">
                        @forelse ($topProducts as $i => $p)
                            <li class="list-group-item d-flex align-items-center gap-3">
                                <span class="avatar avatar-sm avatar-item avatar-title bg-light text-body rounded-2 fw-semibold">{{ $i + 1 }}</span>
                                <div class="flex-grow-1 min-w-0"><div class="fw-medium text-truncate">{{ $p->name }}</div><small class="text-muted">{{ (int) $p->qty }} pcs sold</small></div>
                                <span class="fw-semibold tabular-nums small">{{ money($p->revenue) }}</span>
                            </li>
                        @empty
                            <li class="list-group-item"><x-empty-state icon="ri-t-shirt-2-line" title="No sales this month" /></li>
                        @endforelse
                    </ul>
                </div>
            </div>
        @endif
    </div>
</div>

@script
<script>
    const el = document.getElementById('sales-chart');
    if (el && window.ApexCharts) {
        new ApexCharts(el, {
            chart: { type: 'bar', height: 300, toolbar: { show: false }, fontFamily: 'inherit' },
            series: [{ name: 'Sales', data: @js($chartData) }],
            xaxis: { categories: @js($chartLabels), labels: { rotate: -45, hideOverlappingLabels: true } },
            yaxis: { labels: { formatter: (v) => SGMS.money(v).replace('.00', '') } },
            plotOptions: { bar: { borderRadius: 4, columnWidth: '55%' } },
            dataLabels: { enabled: false },
            colors: [SGMS.color('primary')],
            grid: { borderColor: 'rgba(148,163,184,.2)' },
            tooltip: { y: { formatter: (v) => SGMS.money(v) } },
        }).render();
    }
</script>
@endscript
