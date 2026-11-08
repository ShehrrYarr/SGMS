<?php

namespace App\Services;

use App\Models\Expense;
use App\Models\Inventory;
use App\Models\Sale;
use App\Models\SaleItem;
use App\Models\SalePayment;
use App\Models\SaleReturn;
use App\Models\SaleReturnItem;
use App\Models\StockMovement;
use App\Models\StockSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only reporting queries. Every query starts from a tenant-scoped model
 * and is filtered to the given branch ids; all aggregation happens in SQL.
 *
 * Revenue figures exclude sales tax (tax is owed to the government, not income).
 */
class ReportService
{
    public const SALES_GROUPS = [
        'category' => 'Category',
        'product' => 'Product',
        'brand' => 'Brand',
        'size' => 'Size',
        'color' => 'Colour',
        'staff' => 'Staff',
        'branch' => 'Branch',
        'customer' => 'Customer',
        'day' => 'Day',
        'payment' => 'Payment account',
        'channel' => 'Channel',
    ];

    /** Invoice-level discount spread over the lines, excluding tax. */
    protected const LINE_NET = 'CAST(sale_items.total_price * (sales.subtotal - sales.discount) AS DECIMAL(30,10)) / NULLIF(sales.subtotal, 0)';

    /** Return value excluding the tax share of its invoice (high precision before dividing). */
    protected const RETURN_NET = 'CAST(sale_returns.total_amount * (sales.total_amount - sales.shipping_fee - sales.tax) AS DECIMAL(30,10)) / NULLIF(sales.total_amount - sales.shipping_fee, 0)';

    /* =====================================================================
     | Profit & loss
     * =================================================================== */

    public function profitAndLoss(Carbon $from, Carbon $to, array $branchIds): array
    {
        [$start, $end] = $this->bounds($from, $to);

        $sales = Sale::query()
            ->whereIn('sales.branch_id', $branchIds)
            ->whereBetween('sales.sale_date', [$start, $end])
            ->selectRaw('COUNT(*) invoices, COALESCE(SUM(discount), 0) invoice_discount, COALESCE(SUM(tax), 0) tax, COALESCE(SUM(shipping_fee), 0) delivery, COALESCE(SUM(total_amount), 0) total')
            ->first();

        $items = $this->saleItems($start, $end, $branchIds)
            ->selectRaw('COALESCE(SUM(sale_items.quantity * sale_items.unit_price), 0) gross,
                COALESCE(SUM(sale_items.discount), 0) line_discount,
                COALESCE(SUM(sale_items.quantity), 0) qty,
                COALESCE(SUM(sale_items.quantity * sale_items.cost_price), 0) cogs')
            ->first();

        $returns = $this->returns($start, $end, $branchIds)
            ->selectRaw('COUNT(*) cnt, COALESCE(SUM('.self::RETURN_NET.'), 0) net_value')
            ->first();

        $returnedCost = (float) $this->returnedItems($start, $end, $branchIds)
            ->selectRaw('COALESCE(SUM(sale_return_items.quantity * sale_items.cost_price), 0) cost')
            ->value('cost');

        $expenses = Expense::query()
            ->leftJoin('expense_categories', 'expense_categories.id', '=', 'expenses.expense_category_id')
            ->whereIn('expenses.branch_id', $branchIds)
            ->whereBetween('expenses.expense_date', [$from->toDateString(), $to->toDateString()])
            ->groupByRaw("COALESCE(expense_categories.name, 'Uncategorised')")
            ->selectRaw("COALESCE(expense_categories.name, 'Uncategorised') category, SUM(expenses.amount) total")
            ->orderByDesc('total')
            ->get()
            ->map(fn ($row) => ['category' => $row->category, 'total' => round((float) $row->total, 2)]);

        $adjustments = (float) $this->adjustments($start, $end, $branchIds)
            ->selectRaw('COALESCE(SUM(stock_movements.quantity * COALESCE(stock_movements.unit_cost, product_variants.cost_price)), 0) value')
            ->value('value');

        $gross = (float) $items->gross;
        $discounts = (float) $items->line_discount + (float) $sales->invoice_discount;
        $netSales = $gross - $discounts;
        $returnsValue = (float) $returns->net_value;
        $delivery = (float) $sales->delivery;
        $netRevenue = $netSales - $returnsValue + $delivery;
        $cogs = (float) $items->cogs - $returnedCost;
        $grossProfit = $netRevenue - $cogs;
        $expenseTotal = (float) $expenses->sum('total');
        $netProfit = $grossProfit - $expenseTotal + $adjustments;

        return array_map(fn ($v) => is_float($v) ? round($v, 2) : $v, [
            'gross_sales' => $gross,
            'discounts' => $discounts,
            'net_sales' => $netSales,
            'returns' => $returnsValue,
            'returns_count' => (int) $returns->cnt,
            'delivery' => $delivery,
            'net_revenue' => $netRevenue,
            'cogs' => $cogs,
            'gross_profit' => $grossProfit,
            'gross_margin' => $this->percent($grossProfit, $netRevenue),
            'expenses' => $expenses->all(),
            'expenses_total' => $expenseTotal,
            'adjustments' => $adjustments,
            'net_profit' => $netProfit,
            'net_margin' => $this->percent($netProfit, $netRevenue),
            'tax' => (float) $sales->tax,
            'invoices' => (int) $sales->invoices,
            'items_sold' => (int) $items->qty,
            'avg_invoice' => (int) $sales->invoices > 0 ? ($netSales / (int) $sales->invoices) : 0.0,
        ]);
    }

    /** Revenue, COGS, expenses and profit for each of the last N months. */
    public function monthlyTrend(int $months, array $branchIds): array
    {
        $start = now()->startOfMonth()->subMonths($months - 1);
        $end = now()->endOfMonth();
        $month = fn (string $column) => "DATE_FORMAT({$column}, '%Y-%m')";

        $netSales = Sale::query()->whereIn('sales.branch_id', $branchIds)->whereBetween('sales.sale_date', [$start, $end])
            ->groupByRaw($month('sales.sale_date'))
            ->selectRaw($month('sales.sale_date').' m, SUM(total_amount - tax) v')->pluck('v', 'm');

        $cogs = $this->saleItems($start, $end, $branchIds)
            ->groupByRaw($month('sales.sale_date'))
            ->selectRaw($month('sales.sale_date').' m, SUM(sale_items.quantity * sale_items.cost_price) v')->pluck('v', 'm');

        $returns = $this->returns($start, $end, $branchIds)
            ->groupByRaw($month('sale_returns.return_date'))
            ->selectRaw($month('sale_returns.return_date').' m, SUM('.self::RETURN_NET.') v')->pluck('v', 'm');

        $returnedCost = $this->returnedItems($start, $end, $branchIds)
            ->groupByRaw($month('sale_returns.return_date'))
            ->selectRaw($month('sale_returns.return_date').' m, SUM(sale_return_items.quantity * sale_items.cost_price) v')->pluck('v', 'm');

        $expenses = Expense::query()->whereIn('expenses.branch_id', $branchIds)
            ->whereBetween('expense_date', [$start->toDateString(), $end->toDateString()])
            ->groupByRaw($month('expense_date'))
            ->selectRaw($month('expense_date').' m, SUM(amount) v')->pluck('v', 'm');

        $adjustments = $this->adjustments($start, $end, $branchIds)
            ->groupByRaw($month('stock_movements.moved_at'))
            ->selectRaw($month('stock_movements.moved_at').' m, SUM(stock_movements.quantity * COALESCE(stock_movements.unit_cost, product_variants.cost_price)) v')->pluck('v', 'm');

        return collect(range(0, $months - 1))->map(function ($i) use ($start, $netSales, $cogs, $returns, $returnedCost, $expenses, $adjustments) {
            $key = $start->copy()->addMonthsNoOverflow($i)->format('Y-m');
            $revenue = (float) ($netSales[$key] ?? 0) - (float) ($returns[$key] ?? 0);
            $cost = (float) ($cogs[$key] ?? 0) - (float) ($returnedCost[$key] ?? 0);
            $expense = (float) ($expenses[$key] ?? 0);

            return [
                'month' => Carbon::createFromFormat('Y-m-d', $key.'-01')->format('M y'),
                'revenue' => round($revenue, 2),
                'cogs' => round($cost, 2),
                'gross_profit' => round($revenue - $cost, 2),
                'expenses' => round($expense, 2),
                'net_profit' => round($revenue - $cost - $expense + (float) ($adjustments[$key] ?? 0), 2),
            ];
        })->all();
    }

    /* =====================================================================
     | Sales analysis
     * =================================================================== */

    public function salesBreakdown(Carbon $from, Carbon $to, array $branchIds, string $group, int $limit = 200): Collection
    {
        [$start, $end] = $this->bounds($from, $to);

        if ($group === 'payment') {
            return $this->collectionsByAccount($start, $end, $branchIds);
        }

        $query = $this->saleItems($start, $end, $branchIds);

        if (in_array($group, ['category', 'product', 'brand', 'size', 'color'], true)) {
            $query->join('product_variants', 'product_variants.id', '=', 'sale_items.product_variant_id');
        }

        if (in_array($group, ['category', 'product', 'brand'], true)) {
            $query->join('products', 'products.id', '=', 'product_variants.product_id');
        }

        [$key, $label, $extra] = match ($group) {
            'category' => tap(['products.category_id', "COALESCE(categories.name, 'Uncategorised')", 'NULL'], fn () => $query->leftJoin('categories', 'categories.id', '=', 'products.category_id')),
            'product' => ['products.id', 'products.name', 'products.sku'],
            'brand' => tap(['products.brand_id', "COALESCE(brands.name, 'No brand')", 'NULL'], fn () => $query->leftJoin('brands', 'brands.id', '=', 'products.brand_id')),
            'size' => tap(['product_variants.size_id', "COALESCE(sizes.code, 'One size')", 'MAX(sizes.sort_order)'], fn () => $query->leftJoin('sizes', 'sizes.id', '=', 'product_variants.size_id')),
            'color' => tap(['product_variants.color_id', "COALESCE(colors.name, 'Standard')", 'MAX(colors.hex_code)'], fn () => $query->leftJoin('colors', 'colors.id', '=', 'product_variants.color_id')),
            'staff' => tap(['sales.created_by', "COALESCE(users.name, 'System')", 'MAX(users.role)'], fn () => $query->leftJoin('users', 'users.id', '=', 'sales.created_by')),
            'branch' => tap(['sales.branch_id', 'branches.name', 'MAX(branches.code)'], fn () => $query->join('branches', 'branches.id', '=', 'sales.branch_id')),
            'customer' => tap(['sales.customer_id', "COALESCE(customers.name, 'Walk-in customers')", 'MAX(customers.phone)'], fn () => $query->leftJoin('customers', 'customers.id', '=', 'sales.customer_id')),
            'day' => ['DATE(sales.sale_date)', 'DATE(sales.sale_date)', 'NULL'],
            'channel' => ['sales.channel', "CASE sales.channel WHEN 'online' THEN 'Online store' ELSE 'In-store (POS)' END", 'NULL'],
            default => throw new \InvalidArgumentException("Unknown group [{$group}]."),
        };

        $rows = $query
            ->groupByRaw("{$key}, {$label}".($group === 'product' ? ', products.sku' : ''))
            ->selectRaw("{$key} as group_key, {$label} as label, {$extra} as extra,
                COUNT(DISTINCT sales.id) invoices,
                SUM(sale_items.quantity) qty,
                SUM(".self::LINE_NET.') revenue,
                SUM(sale_items.quantity * sale_items.cost_price) cost')
            ->when($group === 'day', fn ($q) => $q->orderByRaw($key), fn ($q) => $q->orderByDesc('revenue'))
            ->when($group === 'size', fn ($q) => $q->reorder()->orderByRaw('extra'))
            ->limit($limit)
            ->get();

        return $this->withShares($rows->map(fn ($r) => [
            'key' => $r->group_key,
            'label' => (string) $r->label,
            'extra' => $r->extra,
            'invoices' => (int) $r->invoices,
            'qty' => (int) $r->qty,
            'revenue' => round((float) $r->revenue, 2),
            'revenue_exact' => (float) $r->revenue, // unrounded, for totals
            'cost' => round((float) $r->cost, 2),
            'profit' => round((float) $r->revenue - (float) $r->cost, 2),
            'margin' => $this->percent((float) $r->revenue - (float) $r->cost, (float) $r->revenue),
        ]));
    }

    /** Money received per payment account (by payment date). */
    protected function collectionsByAccount(Carbon $start, Carbon $end, array $branchIds): Collection
    {
        $rows = SalePayment::query()
            ->join('sales', 'sales.id', '=', 'sale_payments.sale_id')
            ->leftJoin('payment_accounts', 'payment_accounts.id', '=', 'sale_payments.payment_account_id')
            ->whereIn('sales.branch_id', $branchIds)
            ->whereBetween('sale_payments.payment_date', [$start, $end])
            ->groupByRaw("sale_payments.payment_account_id, COALESCE(payment_accounts.account_name, 'Exchange credit')")
            ->selectRaw("sale_payments.payment_account_id group_key, COALESCE(payment_accounts.account_name, 'Exchange credit') label,
                MAX(payment_accounts.account_type) extra, COUNT(DISTINCT sale_payments.sale_id) invoices, SUM(sale_payments.amount) revenue")
            ->orderByDesc('revenue')
            ->get();

        return $this->withShares($rows->map(fn ($r) => [
            'key' => $r->group_key, 'label' => $r->label, 'extra' => $r->extra, 'invoices' => (int) $r->invoices,
            'qty' => null, 'revenue' => round((float) $r->revenue, 2), 'revenue_exact' => (float) $r->revenue, 'cost' => null, 'profit' => null, 'margin' => null,
        ]));
    }

    /* =====================================================================
     | Stock
     * =================================================================== */

    /** Current stock value grouped by category or branch. */
    public function stockValuation(array $branchIds, string $by = 'category'): Collection
    {
        $query = Inventory::query()
            ->join('product_variants', 'product_variants.id', '=', 'inventories.product_variant_id')
            ->whereIn('inventories.branch_id', $branchIds)
            ->where('inventories.quantity', '>', 0);

        if ($by === 'branch') {
            $query->join('branches', 'branches.id', '=', 'inventories.branch_id');
            [$key, $label] = ['inventories.branch_id', 'branches.name'];
        } else {
            $query->join('products', 'products.id', '=', 'product_variants.product_id')
                ->leftJoin('categories', 'categories.id', '=', 'products.category_id');
            [$key, $label] = ['products.category_id', "COALESCE(categories.name, 'Uncategorised')"];
        }

        $rows = $query->groupByRaw("{$key}, {$label}")
            ->selectRaw("{$label} label, COUNT(DISTINCT product_variants.id) variants, SUM(inventories.quantity) units,
                SUM(inventories.quantity * product_variants.cost_price) cost_value,
                SUM(inventories.quantity * product_variants.retail_price) retail_value")
            ->orderByDesc('cost_value')
            ->get();

        $total = (float) $rows->sum('cost_value');

        return $rows->map(fn ($r) => [
            'label' => $r->label,
            'variants' => (int) $r->variants,
            'units' => (int) $r->units,
            'cost_value' => round((float) $r->cost_value, 2),
            'retail_value' => round((float) $r->retail_value, 2),
            'markup' => $this->percent((float) $r->retail_value - (float) $r->cost_value, (float) $r->cost_value),
            'share' => $this->percent((float) $r->cost_value, $total),
        ]);
    }

    /**
     * Stock value over time from daily snapshots, down-sampled to keep the
     * chart readable: daily (<= 92 days), weekly (<= 1 year), else monthly.
     */
    public function stockHistory(Carbon $from, Carbon $to, array $branchIds): array
    {
        $daily = StockSnapshot::query()
            ->whereIn('branch_id', $branchIds)
            ->whereBetween('snapshot_date', [$from->toDateString(), min($to, today())->toDateString()])
            ->groupBy('snapshot_date')
            ->selectRaw('snapshot_date, SUM(units) units, SUM(cost_value) cost_value, SUM(retail_value) retail_value, MAX(reconstructed) reconstructed')
            ->orderBy('snapshot_date')
            ->get()
            ->map(fn ($r) => [
                'date' => Carbon::parse($r->snapshot_date),
                'units' => (int) $r->units,
                'cost_value' => round((float) $r->cost_value, 2),
                'retail_value' => round((float) $r->retail_value, 2),
                'reconstructed' => (bool) $r->reconstructed,
            ]);

        $days = $from->diffInDays($to) + 1;
        [$bucket, $format] = match (true) {
            $days <= 92 => [fn (Carbon $d) => $d->toDateString(), 'd M'],
            $days <= 366 => [fn (Carbon $d) => $d->copy()->endOfWeek()->toDateString(), 'd M'],
            default => [fn (Carbon $d) => $d->format('Y-m'), 'M Y'],
        };

        // Keep the last snapshot of each bucket (closing value of the period).
        return $daily->groupBy(fn ($row) => $bucket($row['date']))
            ->map(fn ($rows) => $rows->last())
            ->values()
            ->map(fn ($row) => ['label' => $row['date']->format($format)] + $row)
            ->all();
    }

    /** Units in/out by movement type for the period, with opening and closing units. */
    public function stockFlow(Carbon $from, Carbon $to, array $branchIds): array
    {
        [$start, $end] = $this->bounds($from, $to);

        $opening = (int) StockMovement::query()->whereIn('branch_id', $branchIds)->where('moved_at', '<', $start)->sum('quantity');

        $byType = StockMovement::query()->whereIn('branch_id', $branchIds)
            ->whereBetween('moved_at', [$start, $end])
            ->groupBy('type')
            ->selectRaw('type, SUM(quantity) qty')
            ->pluck('qty', 'type')
            ->map(fn ($q) => (int) $q);

        return [
            'opening' => $opening,
            'types' => collect(StockMovement::TYPES)->mapWithKeys(fn ($label, $type) => [$type => ['label' => $label, 'qty' => (int) ($byType[$type] ?? 0)]])->all(),
            'closing' => $opening + (int) $byType->sum(),
        ];
    }

    /** In-stock variants with no sale in the last N days (seasonal dead stock). */
    public function agedStock(array $branchIds, int $days = 60, int $limit = 15): Collection
    {
        $cutoff = now()->subDays($days);

        $lastSold = SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereColumn('sale_items.product_variant_id', 'product_variants.id')
            ->whereIn('sales.branch_id', $branchIds)
            ->selectRaw('MAX(sales.sale_date)');

        $firstReceived = StockMovement::query()
            ->whereColumn('stock_movements.product_variant_id', 'product_variants.id')
            ->whereIn('stock_movements.branch_id', $branchIds)
            ->where('stock_movements.type', 'purchase')
            ->selectRaw('MIN(stock_movements.moved_at)');

        return Inventory::query()
            ->join('product_variants', 'product_variants.id', '=', 'inventories.product_variant_id')
            ->join('products', 'products.id', '=', 'product_variants.product_id')
            ->leftJoin('colors', 'colors.id', '=', 'product_variants.color_id')
            ->leftJoin('sizes', 'sizes.id', '=', 'product_variants.size_id')
            ->whereIn('inventories.branch_id', $branchIds)
            ->where('inventories.quantity', '>', 0)
            ->groupBy('product_variants.id', 'products.name', 'colors.name', 'colors.hex_code', 'sizes.code', 'product_variants.sku', 'product_variants.cost_price')
            ->select('product_variants.id', 'products.name', 'colors.name as color', 'colors.hex_code', 'sizes.code as size', 'product_variants.sku')
            ->selectRaw('SUM(inventories.quantity) units, SUM(inventories.quantity) * product_variants.cost_price cost_value')
            ->selectSub($lastSold, 'last_sold')
            ->selectSub($firstReceived, 'first_received')
            ->havingRaw('(last_sold IS NULL AND (first_received IS NULL OR first_received < ?)) OR last_sold < ?', [$cutoff, $cutoff])
            ->orderByDesc('cost_value')
            ->limit($limit)
            ->get()
            ->map(fn ($r) => [
                'name' => $r->name,
                'variant' => collect([$r->color, $r->size])->filter()->implode(' / ') ?: 'Standard',
                'hex' => $r->hex_code,
                'sku' => $r->sku,
                'units' => (int) $r->units,
                'cost_value' => round((float) $r->cost_value, 2),
                'last_sold' => $r->last_sold ? Carbon::parse($r->last_sold) : null,
                'age_days' => (int) Carbon::parse($r->last_sold ?? $r->first_received ?? now())->diffInDays(now()),
            ]);
    }

    public function missingSnapshotDays(Carbon $from, Carbon $to, array $branchIds): int
    {
        $to = min($to, today());

        if ($from->gt($to)) {
            return 0;
        }

        $have = StockSnapshot::query()->whereIn('branch_id', $branchIds)
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->distinct()->count('snapshot_date');

        return max(0, (int) $from->diffInDays($to) + 1 - $have);
    }

    /* ------------------------------------------------------------------ */

    protected function saleItems(Carbon $start, Carbon $end, array $branchIds)
    {
        return SaleItem::query()
            ->join('sales', 'sales.id', '=', 'sale_items.sale_id')
            ->whereIn('sales.branch_id', $branchIds)
            ->whereBetween('sales.sale_date', [$start, $end]);
    }

    protected function returns(Carbon $start, Carbon $end, array $branchIds)
    {
        return SaleReturn::query()
            ->join('sales', 'sales.id', '=', 'sale_returns.sale_id')
            ->whereIn('sale_returns.branch_id', $branchIds)
            ->whereBetween('sale_returns.return_date', [$start, $end]);
    }

    /** Restocked returned items (their cost comes back out of COGS). */
    protected function returnedItems(Carbon $start, Carbon $end, array $branchIds)
    {
        return SaleReturnItem::query()
            ->join('sale_returns', 'sale_returns.id', '=', 'sale_return_items.sale_return_id')
            ->join('sale_items', 'sale_items.id', '=', 'sale_return_items.sale_item_id')
            ->whereIn('sale_returns.branch_id', $branchIds)
            ->whereBetween('sale_returns.return_date', [$start, $end])
            ->where('sale_return_items.restock', true);
    }

    /** Stock count corrections (negative = shrinkage / write-off). */
    protected function adjustments(Carbon $start, Carbon $end, array $branchIds)
    {
        return StockMovement::query()
            ->join('product_variants', 'product_variants.id', '=', 'stock_movements.product_variant_id')
            ->where('stock_movements.type', 'adjustment')
            ->whereIn('stock_movements.branch_id', $branchIds)
            ->whereBetween('stock_movements.moved_at', [$start, $end]);
    }

    protected function withShares(Collection $rows): Collection
    {
        $total = (float) $rows->sum('revenue_exact');

        return $rows->map(fn ($row) => $row + ['share' => $this->percent($row['revenue_exact'], $total)])->values();
    }

    protected function bounds(Carbon $from, Carbon $to): array
    {
        return [$from->copy()->startOfDay(), $to->copy()->endOfDay()];
    }

    protected function percent(float $part, float $whole): float
    {
        return abs($whole) < 0.005 ? 0.0 : round($part / $whole * 100, 1);
    }
}
