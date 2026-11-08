<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use App\Models\Concerns\HasBranchScope;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class SaleReturn extends Model
{
    use BelongsToTenant, HasBranchScope;

    public const METHODS = [
        'cash' => 'Cash Refund',
        'bank' => 'Bank Refund',
        'wallet' => 'Wallet Refund',
        'ledger' => 'Customer Account Credit',
        'exchange' => 'Exchange',
    ];

    protected $fillable = [
        'tenant_id', 'branch_id', 'sale_id', 'customer_id', 'return_number', 'total_amount',
        'refund_method', 'payment_account_id', 'adjusted_amount', 'refunded_amount', 'exchange_sale_id', 'status',
        'reason', 'return_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'total_amount' => 'decimal:2',
            'adjusted_amount' => 'decimal:2',
            'refunded_amount' => 'decimal:2',
            'return_date' => 'datetime',
        ];
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function exchangeSale(): BelongsTo
    {
        return $this->belongsTo(Sale::class, 'exchange_sale_id');
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function items(): HasMany
    {
        return $this->hasMany(SaleReturnItem::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /** Credit still available for an exchange (value not used against the invoice due). */
    public function getExchangeCreditAttribute(): float
    {
        return round_money((float) $this->total_amount - (float) $this->adjusted_amount);
    }

    public function isPendingExchange(): bool
    {
        return $this->status === 'pending_exchange';
    }
}
