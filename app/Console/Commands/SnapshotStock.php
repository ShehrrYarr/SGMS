<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\StockSnapshotService;
use Illuminate\Console\Command;

/**
 * Captures today's stock valuation for every active shop (scheduled daily).
 *
 *   php artisan sgms:stock-snapshot                 # today, all shops
 *   php artisan sgms:stock-snapshot --days=90       # also rebuild missing days
 *   php artisan sgms:stock-snapshot --tenant=urban-garments
 */
class SnapshotStock extends Command
{
    protected $signature = 'sgms:stock-snapshot
        {--tenant= : Shop slug (default: all active shops)}
        {--days=0 : Also rebuild up to N missing past days from stock movements}';

    protected $description = 'Record daily stock valuation snapshots for reporting';

    public function handle(StockSnapshotService $snapshots): int
    {
        $tenants = Tenant::query()
            ->where('status', Tenant::STATUS_ACTIVE)
            ->when($this->option('tenant'), fn ($q, $slug) => $q->where('slug', $slug))
            ->get();

        $days = max(0, (int) $this->option('days'));

        foreach ($tenants as $tenant) {
            tenancy()->run($tenant, function () use ($snapshots, $days, $tenant) {
                $written = $days > 0
                    ? $snapshots->backfill(today()->subDays($days), today())
                    : tap(1, fn () => $snapshots->capture());

                $this->line("{$tenant->name}: {$written} day(s) captured.");
            });
        }

        $this->info("Done ({$tenants->count()} shop(s)).");

        return self::SUCCESS;
    }
}
