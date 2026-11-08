<?php

namespace App\Models;

use App\Models\Concerns\BelongsToTenant;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\Storage;

/**
 * Online store order. While `pending` its items are reserved against the
 * shop's combined stock; confirming it creates a regular sale invoice in the
 * chosen branch (stock, ledger and accounts are posted by SaleService).
 */
class Order extends Model
{
    use BelongsToTenant;

    /** Payment receipts: private disk, never a public URL. */
    public const PROOF_DISK = 'local';

    public const PROOF_DIR = 'payments';

    public const STATUSES = [
        'pending' => 'New',
        'confirmed' => 'Confirmed',
        'shipped' => 'Shipped',
        'delivered' => 'Delivered',
        'cancelled' => 'Cancelled',
    ];

    public const PAYMENT_METHODS = [
        'cod' => 'Cash on delivery',
        'bank' => 'Bank transfer',
        'wallet' => 'JazzCash / Easypaisa',
    ];

    public const PAYMENT_STATUSES = [
        'unpaid' => 'Unpaid',
        'awaiting' => 'Awaiting verification',
        'paid' => 'Paid',
        'refunded' => 'Refunded',
    ];

    protected $fillable = [
        'tenant_id', 'order_number', 'customer_id', 'sale_id', 'branch_id', 'status',
        'payment_method', 'payment_account_id', 'payment_status', 'payment_proof', 'payment_reference',
        'customer_name', 'phone', 'email', 'address', 'city', 'notes',
        'subtotal', 'discount', 'coupon_id', 'coupon_code', 'tax_rate', 'tax', 'shipping_fee', 'total',
        'courier', 'tracking_number', 'confirmed_at', 'shipped_at', 'delivered_at', 'cancelled_at',
        'cancel_reason', 'seen_at', 'handled_by',
    ];

    protected function casts(): array
    {
        return [
            'subtotal' => 'decimal:2',
            'discount' => 'decimal:2',
            'tax_rate' => 'decimal:2',
            'tax' => 'decimal:2',
            'shipping_fee' => 'decimal:2',
            'total' => 'decimal:2',
            'confirmed_at' => 'datetime',
            'shipped_at' => 'datetime',
            'delivered_at' => 'datetime',
            'cancelled_at' => 'datetime',
            'seen_at' => 'datetime',
        ];
    }

    public function getRouteKeyName(): string
    {
        return 'order_number';
    }

    /* Relationships ------------------------------------------------------ */

    public function items(): HasMany
    {
        return $this->hasMany(OrderItem::class);
    }

    public function customer(): BelongsTo
    {
        return $this->belongsTo(Customer::class)->withTrashed();
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(Sale::class);
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(Branch::class);
    }

    public function paymentAccount(): BelongsTo
    {
        return $this->belongsTo(PaymentAccount::class);
    }

    public function coupon(): BelongsTo
    {
        return $this->belongsTo(Coupon::class);
    }

    public function handler(): BelongsTo
    {
        return $this->belongsTo(User::class, 'handled_by');
    }

    /* Scopes ------------------------------------------------------------- */

    public function scopeStatus(Builder $query, ?string $status): Builder
    {
        return $query->when($status, fn (Builder $q) => $q->where('status', $status));
    }

    public function scopeSearch(Builder $query, ?string $term): Builder
    {
        $term = trim((string) $term);

        return $query->when($term !== '', fn (Builder $q) => $q->where(fn (Builder $q) => $q
            ->where('order_number', 'like', "%{$term}%")
            ->orWhere('customer_name', 'like', "%{$term}%")
            ->orWhere('phone', 'like', '%'.preg_replace('/\D/', '', $term).'%')));
    }

    /* Helpers ------------------------------------------------------------ */

    public function isOpen(): bool
    {
        return in_array($this->status, ['pending', 'confirmed', 'shipped'], true);
    }

    public function isPrepaid(): bool
    {
        return in_array($this->payment_method, ['bank', 'wallet'], true);
    }

    public function getStatusLabelAttribute(): string
    {
        return self::STATUSES[$this->status] ?? ucfirst($this->status);
    }

    public function getPaymentMethodLabelAttribute(): string
    {
        return self::PAYMENT_METHODS[$this->payment_method] ?? ucfirst($this->payment_method);
    }

    public function getPaymentStatusLabelAttribute(): string
    {
        return self::PAYMENT_STATUSES[$this->payment_status] ?? ucfirst($this->payment_status);
    }

    /**
     * Payment receipts are personal (names, account numbers), so they live on
     * the private disk and are only served through app.orders.proof to staff.
     * Receipts saved before this change may still be on the public disk.
     */
    public function getPaymentProofUrlAttribute(): ?string
    {
        return $this->payment_proof ? route('app.orders.proof', $this) : null;
    }

    /** The disk that holds this order's receipt, or null when the file is gone. */
    public function proofDisk(): ?string
    {
        foreach ([self::PROOF_DISK, 'public'] as $disk) {
            if ($this->payment_proof && Storage::disk($disk)->exists($this->payment_proof)) {
                return $disk;
            }
        }

        return null;
    }

    public static function deleteProof(?string $path): void
    {
        if ($path) {
            Storage::disk(self::PROOF_DISK)->delete($path);
            Storage::disk('public')->delete($path);
        }
    }

    /** Progress steps for the tracking timeline: [label => reached at]. */
    public function timeline(): array
    {
        if ($this->status === 'cancelled') {
            return ['Order placed' => $this->created_at, 'Cancelled' => $this->cancelled_at];
        }

        return [
            'Order placed' => $this->created_at,
            'Confirmed' => $this->confirmed_at,
            'Shipped' => $this->shipped_at,
            'Delivered' => $this->delivered_at,
        ];
    }

    /** wa.me link with a ready-made message to the customer. */
    public function whatsappUrl(string $message): string
    {
        $digits = preg_replace('/\D/', '', $this->phone);

        if (str_starts_with($digits, '0')) {
            $digits = '92'.substr($digits, 1);
        }

        return 'https://wa.me/'.$digits.'?text='.rawurlencode($message);
    }
}
