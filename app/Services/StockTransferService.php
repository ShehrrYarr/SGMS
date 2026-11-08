<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Branch;
use App\Models\StockTransfer;
use Illuminate\Support\Facades\DB;

class StockTransferService
{
    public function __construct(protected InventoryService $inventory) {}

    /**
     * @param  array<int, array{variant_id:int, quantity:int}>  $items
     */
    public function create(int $fromBranchId, int $toBranchId, array $items, ?string $date = null, ?string $notes = null): StockTransfer
    {
        if ($fromBranchId === $toBranchId) {
            throw new BusinessException('Source and destination branches must be different.');
        }

        $items = collect($items)->filter(fn ($i) => (int) ($i['quantity'] ?? 0) > 0)
            ->groupBy('variant_id')
            ->map(fn ($rows, $variantId) => ['variant_id' => (int) $variantId, 'quantity' => (int) $rows->sum('quantity')])
            ->values();

        if ($items->isEmpty()) {
            throw new BusinessException('Add at least one item to transfer.');
        }

        return DB::transaction(function () use ($fromBranchId, $toBranchId, $items, $date, $notes) {
            $from = Branch::query()->findOrFail($fromBranchId);
            $to = Branch::query()->findOrFail($toBranchId);

            $transfer = StockTransfer::create([
                'transfer_number' => DocumentNumber::next('transfer', 5),
                'from_branch_id' => $from->id,
                'to_branch_id' => $to->id,
                'total_quantity' => $items->sum('quantity'),
                'status' => 'completed',
                'transfer_date' => $date ?? today()->toDateString(),
                'notes' => $notes,
                'created_by' => auth('web')->id(),
            ]);

            foreach ($items as $item) {
                $transfer->items()->create([
                    'tenant_id' => $transfer->tenant_id,
                    'product_variant_id' => $item['variant_id'],
                    'quantity' => $item['quantity'],
                ]);

                $this->inventory->adjust($from->id, $item['variant_id'], -$item['quantity'], 'transfer_out', 'stock_transfer', $transfer->id, note: "{$transfer->transfer_number} to {$to->name}");
                $this->inventory->adjust($to->id, $item['variant_id'], $item['quantity'], 'transfer_in', 'stock_transfer', $transfer->id, note: "{$transfer->transfer_number} from {$from->name}");
            }

            return $transfer;
        }, 3);
    }
}
