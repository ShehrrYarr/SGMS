<?php

namespace App\Http\Controllers;

use App\Models\Customer;
use App\Models\Purchase;
use App\Models\Sale;
use App\Models\Vendor;
use App\Models\VendorLedger;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Symfony\Component\HttpFoundation\Response;

/**
 * Downloadable PDFs: ledgers, statements, receipts and invoices.
 */
class PdfController extends Controller
{
    public function vendorLedger(Request $request, Vendor $vendor): Response
    {
        [$from, $to] = $this->period($request);

        $opening = (float) ($vendor->ledgers()->where('transaction_date', '<', $from)->latest('id')->value('running_balance') ?? 0);
        $entries = $vendor->ledgers()->with('paymentAccount', 'purchase')
            ->whereBetween('transaction_date', [$from, $to])->orderBy('id')->get();

        return $this->download('pdf.vendor-ledger', compact('vendor', 'entries', 'opening', 'from', 'to'), "vendor-ledger-{$vendor->id}.pdf");
    }

    public function vendorReceipt(Vendor $vendor, VendorLedger $entry): Response
    {
        abort_unless($entry->vendor_id === $vendor->id && $entry->type === 'payment', 404);
        $entry->load('paymentAccount', 'purchase', 'creator', 'purchasePayments.purchase');

        return $this->download('pdf.vendor-receipt', compact('vendor', 'entry'), "payment-voucher-{$entry->id}.pdf");
    }

    public function purchase(Purchase $purchase): Response
    {
        abort_unless(tenancy()->canAccessBranch($purchase->branch_id), 403);
        $purchase->load('vendor', 'branch', 'creator', 'items.variant.product', 'items.variant.color', 'items.variant.size', 'payments.paymentAccount');

        return $this->download('pdf.purchase', compact('purchase'), "{$purchase->lot_number}.pdf");
    }

    public function saleInvoice(Request $request, Sale $sale): Response
    {
        $user = $request->user('web');
        abort_unless(tenancy()->canAccessBranch($sale->branch_id), 403);
        abort_if($user->isCashier() && $sale->created_by !== $user->id, 403);

        return $this->invoice($sale);
    }

    public function customerStatement(Request $request, Customer $customer): Response
    {
        return $this->statement($request, $customer);
    }

    public function portalStatement(Request $request): Response
    {
        return $this->statement($request, $request->user('customer'));
    }

    /* ------------------------------------------------------------------ */

    protected function invoice(Sale $sale): Response
    {
        $sale->load('items.variant.product', 'items.variant.color', 'items.variant.size', 'payments.paymentAccount', 'customer', 'branch', 'creator');

        return $this->download('pdf.sale-invoice', compact('sale'), "{$sale->invoice_number}.pdf");
    }

    protected function statement(Request $request, Customer $customer): Response
    {
        [$from, $to] = $this->period($request);

        $opening = (float) ($customer->ledgers()->where('transaction_date', '<', $from)->latest('id')->value('running_balance') ?? 0);
        $entries = $customer->ledgers()->with('paymentAccount', 'sale')
            ->whereBetween('transaction_date', [$from, $to])->orderBy('id')->get();

        return $this->download('pdf.customer-statement', compact('customer', 'entries', 'opening', 'from', 'to'), "statement-{$customer->id}.pdf");
    }

    protected function period(Request $request): array
    {
        $from = $request->date('from') ?? Carbon::create(2000);
        $to = $request->date('to') ?? today();

        return [$from->startOfDay()->toDateString(), $to->endOfDay()->toDateString()];
    }

    protected function download(string $view, array $data, string $filename): Response
    {
        $data['tenant'] = tenant();

        return Pdf::loadView($view, $data)->setPaper('a4')->download($filename);
    }
}
