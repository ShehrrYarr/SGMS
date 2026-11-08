<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBranchScope;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class Sale extends Model
{
    use BelongsToTenant, HasBranchScope, HasFactory;

    protected $fillable = [
        'tenant_id', 'branch_id', 'customer_id', 'invoice_number', 'subtotal', 'discount', 'tax',
        'total_amount', 'paid_amount', 'due_amount', 'returned_amount', 'payment_status',
        'payment_method', 'sale_date', 'notes', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax' => 'decimal:2',
            'total_amount' => 'decimal:2',
            'paid_amount' => 'decimal:2',
            'due_amount' => 'decimal:2',
            'returned_amount' => 'decimal:2',
            'sale_date' => 'datetime',
        ];
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleItem::class);
    }

    public function payments(): HasMany
    {
        return $this->hasMany(SalePayment::class);
    }

    public function returns(): HasMany
    {
        return $this->hasMany(SaleReturn::class);
    }

    public function ledgerEntries(): HasMany
    {
        return $this->hasMany(CustomerLedger::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getCustomerNameAttribute(): string
    {
        return $this->customer?->name ?? 'Walk-in Customer';
    }

    public static function statusFor(float $total, float $paid): string
    {
        return match (true) {
            $total <= 0, $paid + 0.009 >= $total => 'paid',
            $paid <= 0 => 'unpaid',
            default => 'partial',
        };
    }
}
