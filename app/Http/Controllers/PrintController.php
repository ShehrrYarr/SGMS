<?php

namespace App\Http\Controllers;

use App\Models\Sale;
use App\Services\BarcodeService;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Thermal receipts (80mm / 58mm), printed via hidden iframe from the POS.
 */
class PrintController extends Controller
{
    public function receipt(Request $request, Sale $sale, BarcodeService $barcodes): View
    {
        $user = $request->user('web');
        abort_unless(tenancy()->canAccessBranch($sale->branch_id), 403);
        abort_if($user->isCashier() && $sale->created_by !== $user->id, 403);

        return $this->render($request, $sale, $barcodes);
    }

    public function portalReceipt(Request $request, string $tenant, Sale $sale, BarcodeService $barcodes): View
    {
        abort_unless($sale->customer_id === $request->user('customer')->id, 404);

        return $this->render($request, $sale, $barcodes);
    }

    protected function render(Request $request, Sale $sale, BarcodeService $barcodes): View
    {
        $sale->load(['items.variant.product', 'items.variant.color', 'items.variant.size', 'payments.paymentAccount', 'customer', 'branch', 'creator']);
        $tenant = tenant();
        $width = in_array($request->query('width'), ['58', '80'], true) ? $request->query('width') : $tenant->setting('receipt_width', '80');

        return view('print.receipt', [
            'sale' => $sale,
            'tenant' => $tenant,
            'width' => $width,
            'autoprint' => $request->boolean('autoprint'),
            'tendered' => (float) $request->query('tendered', 0),
            'change' => (float) $request->query('change', 0),
            'barcode' => $barcodes->svg($sale->invoice_number, 200, 34),
        ]);
    }
}
