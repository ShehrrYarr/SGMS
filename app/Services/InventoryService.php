<?php

namespace App\Services;

use App\Exceptions\BusinessException;
use App\Models\Inventory;
use App\Models\ProductVariant;
use App\Models\StockMovement;
use Illuminate\Support\Facades\DB;

/**
 * Single entry point for stock changes. Locks the branch/variant row,
 * prevents overselling and writes an auditable stock movement.
 */
class InventoryService
{
    public function adjust(
        int $branchId,
        int $variantId,
        int $quantity,
        string $type,
        ?string $referenceType = null,
        ?int $referenceId = null,
        ?float $unitCost = null,
        ?string $note = null,
        bool $allowNegative = false,
    ): int {
        if ($quantity === 0) {
            return $this->available($branchId, $variantId);
        }

        return DB::transaction(function () use ($branchId, $variantId, $quantity, $type, $referenceType, $referenceId, $unitCost, $note, $allowNegative) {
            $tenantId = tenant_id();

            DB::table('inventories')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'product_variant_id' => $variantId,
                'quantity' => 0,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $inventory = Inventory::query()
                ->where('branch_id', $branchId)
                ->where('product_variant_id', $variantId)
                ->lockForUpdate()
                ->firstOrFail();

            $newQuantity = $inventory->quantity + $quantity;

            if ($newQuantity < 0 && ! $allowNegative) {
                $label = ProductVariant::withTrashed()->with(['product', 'color', 'size'])->find($variantId)?->label ?? 'item';

                throw new BusinessException("Not enough stock for {$label}. Available: {$inventory->quantity}, requested: ".abs($quantity).'.');
            }

            $inventory->forceFill(['quantity' => $newQuantity])->save();

            StockMovement::create([
                'tenant_id' => $tenantId,
                'branch_id' => $branchId,
                'product_variant_id' => $variantId,
                'type' => $type,
                'quantity' => $quantity,
                'balance_after' => $newQuantity,
                'reference_type' => $referenceType,
                'reference_id' => $referenceId,
                'unit_cost' => $unitCost,
                'note' => $note,
                'created_by' => auth('web')->id(),
            ]);

            return $newQuantity;
        }, 3);
    }

    /** Set stock to a physically counted quantity. */
    public function setCounted(int $branchId, int $variantId, int $countedQuantity, ?string $note = null): int
    {
        $current = $this->available($branchId, $variantId);

        return $this->adjust($branchId, $variantId, $countedQuantity - $current, 'adjustment', note: $note ?: 'Stock count adjustment', allowNegative: true);
    }

    public function available(int $branchId, int $variantId): int
    {
        return (int) Inventory::query()
            ->where('branch_id', $branchId)
            ->where('product_variant_id', $variantId)
            ->value('quantity');
    }

    /** Total stock of a variant across every branch of the shop. */
    public function totalStock(int $variantId): int
    {
        return (int) Inventory::query()->where('product_variant_id', $variantId)->sum('quantity');
    }
}
