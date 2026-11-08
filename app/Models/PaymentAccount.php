<?php

namespace App\Models;

use App\Exceptions\BusinessException;
use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class PaymentAccount extends Model
{
    use BelongsToTenant, HasFactory;

    public const TYPE_CASH = 'cash_drawer';

    public const TYPE_BANK = 'bank';

    public const TYPE_WALLET = 'wallet';

    public const TYPES = [
        self::TYPE_CASH => 'Cash Drawer',
        self::TYPE_BANK => 'Bank Account',
        self::TYPE_WALLET => 'Mobile Wallet',
    ];

    protected $fillable = [
        'tenant_id', 'branch_id', 'account_name', 'account_type', 'bank_name',
        'account_number', 'opening_balance', 'current_balance', 'is_default', 'status',
    ];

    protected function casts(): array
    {
        return [
            'opening_balance' => 'decimal:2',
            'current_balance' => 'decimal:2',
            'is_default' => 'boolean',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function transactions(): HasMany
    {
        return $this->hasMany(Transaction::class);
    }

    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', 'active');
    }

    /** Accounts usable from a branch: its own drawers + shop-wide banks/wallets. */
    public function scopeUsableAt(Builder $query, ?int $branchId): Builder
    {
        return $query->where(fn (Builder $q) => $q->whereNull('branch_id')->orWhere('branch_id', $branchId));
    }

    /**
     * The account a payment or refund may move money through. It must be
     * active, shop-wide or the drawer of $branchId (when the money belongs to a
     * branch), and in a branch the signed-in user can access. Every service
     * that takes an account id from a form goes through here, so a tampered id
     * cannot pay or refund out of another branch's drawer.
     */
    public static function usableOrFail(?int $id, ?int $branchId, string $missing = 'Choose the account to use.'): self
    {
        $account = $id ? static::query()->find($id) : null;

        if (! $account) {
            throw new BusinessException($missing);
        }

        if ($account->status !== 'active') {
            throw new BusinessException("{$account->account_name} is inactive.");
        }

        if ($account->branch_id !== null) {
            $context = tenancy();

            if (($branchId !== null && $account->branch_id !== $branchId)
                || ($context->user() && ! $context->canAccessBranch($account->branch_id))) {
                throw new BusinessException("{$account->account_name} belongs to another branch. Use this branch's drawer or a shop-wide account.");
            }
        }

        return $account;
    }

    public function isCash(): bool
    {
        return $this->account_type === self::TYPE_CASH;
    }

    /** Payment method label stored on payments: cash | bank | wallet. */
    public function paymentMethod(): string
    {
        return match ($this->account_type) {
            self::TYPE_CASH => 'cash',
            self::TYPE_WALLET => 'wallet',
            default => 'bank',
        };
    }

    public function getLabelAttribute(): string
    {
        $suffix = $this->account_number ? ' ••'.substr($this->account_number, -4) : '';

        return $this->account_name.$suffix;
    }

    public function getIconAttribute(): string
    {
        return match ($this->account_type) {
            self::TYPE_CASH => 'ri-safe-2-line',
            self::TYPE_WALLET => 'ri-smartphone-line',
            default => 'ri-bank-line',
        };
    }
}
