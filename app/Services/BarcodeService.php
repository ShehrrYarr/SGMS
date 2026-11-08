<?php

namespace App\Services;

use App\Models\ProductVariant;
use Picqer\Barcode\Renderers\SvgRenderer;
use Picqer\Barcode\Types\TypeCode128;
use Picqer\Barcode\Types\TypeEan13;
use Throwable;

/**
 * Internal barcodes are valid EAN-13 numbers in the GS1 "in-store" range
 * (prefix 2): 2 + tenant(4) + sequence(7) + check digit. Any scanner reads them.
 */
class BarcodeService
{
    public function generate(): string
    {
        $tenant = str_pad((string) (tenant_id() % 10000), 4, '0', STR_PAD_LEFT);

        do {
            $sequence = str_pad((string) DocumentNumber::nextValue('barcode'), 7, '0', STR_PAD_LEFT);
            $base = '2'.$tenant.$sequence;
            $code = $base.$this->ean13CheckDigit($base);
        } while (ProductVariant::withTrashed()->where('barcode', $code)->exists());

        return $code;
    }

    public function isEan13(string $code): bool
    {
        return (bool) preg_match('/^\d{13}$/', $code)
            && (int) $code[12] === $this->ean13CheckDigit(substr($code, 0, 12));
    }

    /** Render a barcode as inline SVG (EAN-13 when valid, otherwise Code 128). */
    public function svg(string $code, float $width = 180, float $height = 40): string
    {
        try {
            $type = $this->isEan13($code) ? new TypeEan13 : new TypeCode128;
            $barcode = $type->getBarcode($code);

            return (new SvgRenderer)->setSvgType(SvgRenderer::TYPE_SVG_INLINE)->render($barcode, $width, $height);
        } catch (Throwable) {
            return '';
        }
    }

    public function ean13CheckDigit(string $twelveDigits): int
    {
        $sum = 0;

        foreach (str_split($twelveDigits) as $i => $digit) {
            $sum += (int) $digit * ($i % 2 === 0 ? 1 : 3);
        }

        return (10 - ($sum % 10)) % 10;
    }
}
