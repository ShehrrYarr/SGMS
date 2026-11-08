<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBranchScope;
use Illuminate\Database\Eloquent\Model;

/**
 * Daily stock value per branch (written by StockSnapshotService).
 */
class StockSnapshot extends Model
{
    use BelongsToTenant, HasBranchScope;

    protected $fillable = [
        'tenant_id', 'branch_id', 'snapshot_date', 'units', 'cost_value', 'retail_value', 'reconstructed',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'cost_value' => 'decimal:2',
            'retail_value' => 'decimal:2',
            'reconstructed' => 'boolean',
        ];
    }
}
