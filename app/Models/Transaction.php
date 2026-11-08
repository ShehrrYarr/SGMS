<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Money movement journal for payment accounts.
 * credit = money in, debit = money out (bank statement convention).
 */
class Transaction extends Model
{
    use BelongsToTenant, HasFactory;

    public const DEBIT = 'debit';

    public const CREDIT = 'credit';

    public const REFERENCE_LABELS = [
        'purchase' => 'Lot Purchase',
        'sale' => 'Sale',
        'vendor_payment' => 'Vendor Payment',
        'customer_payment' => 'Customer Payment',
        'expense' => 'Expense',
        'sale_return' => 'Sale Return',
        'fund_transfer' => 'Fund Transfer',
        'adjustment' => 'Adjustment',
        'opening' => 'Opening Balance',
    ];

    protected $fillable = [
        'tenant_id', 'branch_id', 'payment_account_id', 'reference_type', 'reference_id',
        'type', 'amount', 'balance_after', 'note', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'amount' => 'decimal:2',
            'balance_after' => 'decimal:2',
        ];
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getReferenceLabelAttribute(): string
    {
        return self::REFERENCE_LABELS[$this->reference_type] ?? ucfirst(str_replace('_', ' ', $this->reference_type));
    }
}
