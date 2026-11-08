<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\StockSnapshot;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;

/**
 * Daily stock valuation per branch.
 *
 * Today's snapshot is read from live inventory at the current weighted
 * average cost. Missing past days can be rebuilt from stock movements:
 * the quantity on a day is the sum of all signed movements up to that day,
 * valued at today's cost (flagged as `reconstructed`). Snapshots captured
 * daily by the scheduler keep the cost that applied on each day.
 *
 * Requires an active tenant context.
 */
class StockSnapshotService
{
    /** Capture (or refresh) one day for every branch of the current shop. */
    public function capture(?Carbon $date = null): void
    {
        $date = ($date ?? today())->copy()->startOfDay();
        $rows = $date->isToday() ? $this->liveTotals() : $this->reconstructedTotals($date);
        $branchIds = Branch::query()->pluck('id');
        $now = now();

        $records = $branchIds->map(fn ($branchId) => [
            'tenant_id' => tenant_id(),
            'branch_id' => $branchId,
            'snapshot_date' => $date->toDateString(),
            'units' => (int) ($rows[$branchId]->units ?? 0),
            'cost_value' => round((float) ($rows[$branchId]->cost_value ?? 0), 2),
            'retail_value' => round((float) ($rows[$branchId]->retail_value ?? 0), 2),
            'reconstructed' => ! $date->isToday(),
            'created_at' => $now,
            'updated_at' => $now,
        ])->all();

        DB::table('stock_snapshots')->upsert(
            $records,
            ['tenant_id', 'branch_id', 'snapshot_date'],
            ['units', 'cost_value', 'retail_value', 'reconstructed', 'updated_at'],
        );
    }

    /**
     * Fill days without a snapshot between $from and $to (inclusive).
     * Returns the number of days written.
     */
    public function backfill(Carbon $from, Carbon $to, bool $overwrite = false): int
    {
        $from = $from->copy()->startOfDay();
        $to = min($to->copy()->startOfDay(), today());

        $existing = $overwrite ? [] : StockSnapshot::query()
            ->whereBetween('snapshot_date', [$from->toDateString(), $to->toDateString()])
            ->distinct()->pluck('snapshot_date')
            ->map(fn ($d) => Carbon::parse($d)->toDateString())->flip()->all();

        $written = 0;

        for ($day = $from->copy(); $day->lte($to); $day->addDay()) {
            if (! isset($existing[$day->toDateString()]) || $day->isToday()) {
                $this->capture($day->copy());
                $written++;
            }
        }

        return $written;
    }

    protected function liveTotals()
    {
        return DB::table('inventories')
            ->join('product_variants', 'product_variants.id', '=', 'inventories.product_variant_id')
            ->where('inventories.tenant_id', tenant_id())
            ->groupBy('inventories.branch_id')
            ->selectRaw('inventories.branch_id, SUM(inventories.quantity) units,
                SUM(GREATEST(inventories.quantity, 0) * product_variants.cost_price) cost_value,
                SUM(GREATEST(inventories.quantity, 0) * product_variants.retail_price) retail_value')
            ->get()->keyBy('branch_id');
    }

    protected function reconstructedTotals(Carbon $date)
    {
        // Quantity per branch/variant at end of day, then valued at current prices.
        $perVariant = DB::table('stock_movements')
            ->where('tenant_id', tenant_id())
            ->where('moved_at', '<=', $date->copy()->endOfDay())
            ->groupBy('branch_id', 'product_variant_id')
            ->selectRaw('branch_id, product_variant_id, SUM(quantity) qty');

        return DB::query()->fromSub($perVariant, 'q')
            ->join('product_variants', 'product_variants.id', '=', 'q.product_variant_id')
            ->groupBy('q.branch_id')
            ->selectRaw('q.branch_id, SUM(q.qty) units,
                SUM(GREATEST(q.qty, 0) * product_variants.cost_price) cost_value,
                SUM(GREATEST(q.qty, 0) * product_variants.retail_price) retail_value')
            ->get()->keyBy('branch_id');
    }
}
