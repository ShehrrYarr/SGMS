<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Purchase extends Model
{
    use BelongsToTenant, HasBranchScope, HasFactory;

    public const METHODS = ['cash' => 'Cash', 'bank' => 'Bank', 'split' => 'Split', 'credit' => 'Credit'];

    protected $fillable = [
        'tenant_id', 'branch_id', 'vendor_id', 'lot_number', 'lot_name', 'total_lot_cost',
        'total_garment_qty', 'payment_status', 'payment_method', 'paid_amount', 'due_amount',
        'purchase_date', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total_lot_cost' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'purchase_date' => 'date',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(PurchaseItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(VendorLedger::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public static function statusFor(float $total, float $paid): string
    {
        return match (true) {
            $paid <= 0 => 'unpaid',
            $paid + 0.009 >= $total => 'paid',
            default => 'partial',
        };
    }
}
