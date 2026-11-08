<?php

namespace App\Services;

use Illuminate\Support\Facades\DB;
use LogicException;

/**
 * Gap-free, race-safe per-tenant document numbering (INV-000001, LOT-000001...).
 * A row lock on document_sequences serialises concurrent checkouts.
 */
class DocumentNumber
{
    public const PREFIXES = [
        'sale' => 'INV',
        'purchase' => 'LOT',
        'return' => 'RET',
        'transfer' => 'TRF',
        'product' => 'PRD',
        'order' => 'ORD',
    ];

    public static function next(string $key, int $pad = 6): string
    {
        return static::format($key, static::nextValue($key), $pad);
    }

    /** The number the next document will most likely get (not reserved). */
    public static function peek(string $key, int $pad = 6): string
    {
        $value = DB::table('document_sequences')
            ->where('tenant_id', static::tenantId())
            ->where('key', $key)
            ->value('next_value') ?? 1;

        return static::format($key, (int) $value, $pad);
    }

    public static function nextValue(string $key): int
    {
        $tenantId = static::tenantId();

        return DB::transaction(function () use ($tenantId, $key) {
            DB::table('document_sequences')->insertOrIgnore([
                'tenant_id' => $tenantId,
                'key' => $key,
                'next_value' => 1,
                'created_at' => now(),
                'updated_at' => now(),
            ]);

            $row = DB::table('document_sequences')
                ->where('tenant_id', $tenantId)
                ->where('key', $key)
                ->lockForUpdate()
                ->first();

            DB::table('document_sequences')->where('id', $row->id)->update([
                'next_value' => $row->next_value + 1,
                'updated_at' => now(),
            ]);

            return (int) $row->next_value;
        });
    }

    protected static function format(string $key, int $value, int $pad): string
    {
        $prefix = self::PREFIXES[$key] ?? strtoupper($key);

        return $prefix.'-'.str_pad((string) $value, $pad, '0', STR_PAD_LEFT);
    }

    protected static function tenantId(): int
    {
        return tenant_id() ?? throw new LogicException('Document numbers require an active tenant.');
    }
}
