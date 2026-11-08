<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class StockMovement extends Model
{
    use BelongsToTenant, HasBranchScope;

    public const TYPES = [
        'purchase' => 'Purchase',
        'sale' => 'Sale',
        'sale_return' => 'Sale Return',
        'transfer_in' => 'Transfer In',
        'transfer_out' => 'Transfer Out',
        'adjustment' => 'Adjustment',
    ];

    protected $fillable = [
        'tenant_id', 'branch_id', 'product_variant_id', 'type', 'quantity', 'balance_after',
        'reference_type', 'reference_id', 'unit_cost', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return ['unit_cost' => 'decimal:2'];
    }

    public function variant(): BelongsTo
    {
        return $this->belongsTo(ProductVariant::class, 'product_variant_id')->withTrashed();
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
