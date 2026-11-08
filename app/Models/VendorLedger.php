<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

/**
 * Accounts payable ledger. credit = we owe more, debit = we owe less.
 */
class VendorLedger extends Model
{
    use BelongsToTenant, HasFactory;

    public const TYPES = [
        'opening_balance' => 'Opening Balance',
        'purchase' => 'Lot Purchase',
        'payment' => 'Payment',
        'adjustment' => 'Adjustment',
    ];

    protected $fillable = [
        'tenant_id', 'vendor_id', 'purchase_id', 'type', 'debit', 'credit', 'running_balance',
        'payment_account_id', 'reference_no', 'note', 'transaction_date', 'created_by',
    ];

    protected function casts(): array
    {
        return [
            'debit' => 'decimal:2',
            'credit' => 'decimal:2',
            'running_balance' => 'decimal:2',
            'transaction_date' => 'date',
        ];
    }

    public function vendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class)->withTrashed();
    }

    public function purchase(): BelongsTo
    {
        return $this->belongsTo(Purchase::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function purchasePayments(): HasMany
    {
        return $this->hasMany(PurchasePayment::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function getTypeLabelAttribute(): string
    {
        return self::TYPES[$this->type] ?? ucfirst($this->type);
    }
}
