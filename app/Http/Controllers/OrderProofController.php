<?php

namespace App\Http\Controllers;

use App\Models\Order;
use Illuminate\Support\Facades\Storage;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * Shows a shopper's payment receipt to shop staff (owner / manager). Receipts
 * are stored privately; this route is the only way to open one.
 */
class OrderProofController extends Controller
{
    public function __invoke(Order $order): StreamedResponse
    {
        $disk = $order->proofDisk();
        abort_unless($disk, 404);

        return Storage::disk($disk)->response($order->payment_proof, $order->order_number.'-receipt.'.pathinfo($order->payment_proof, PATHINFO_EXTENSION), [
            'Cache-Control' => 'private, no-store',
            'X-Content-Type-Options' => 'nosniff',
            'Content-Security-Policy' => "default-src 'none'; img-src 'self'; style-src 'unsafe-inline'; sandbox",
        ]);
    }
}
