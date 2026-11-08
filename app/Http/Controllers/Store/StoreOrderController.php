<?php

namespace App\Http\Controllers\Store;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Models\Order;
use App\Services\Store\OrderService;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Order confirmation, tracking and payment receipt upload for shoppers. */
class StoreOrderController extends Controller
{
    public function track(): View
    {
        return view('store.pages.track');
    }

    public function lookup(Request $request): RedirectResponse
    {
        $data = $request->validate([
            'order_number' => ['required', 'string', 'max:40'],
            'phone' => ['required', 'string', 'max:30'],
        ]);

        $order = Order::query()
            ->where('order_number', strtoupper(trim($data['order_number'])))
            ->where('phone', normalize_phone($data['phone']))
            ->first();

        if (! $order) {
            return back()->withInput()->with('error', 'No order matches that number and phone. Check both and try again.');
        }

        $this->remember($order);

        return redirect()->route('store.order', $order);
    }

    public function show(string $tenant, Order $order): View|RedirectResponse
    {
        if (! $this->canView($order)) {
            return redirect()->route('store.track')->with('error', 'Enter your phone number to view this order.')->withInput(['order_number' => $order->order_number]);
        }

        $order->load(['items.variant.product.colorImages', 'paymentAccount']);

        return view('store.pages.order', [
            'order' => $order,
            'justPlaced' => session('store.placed') === $order->id,
        ]);
    }

    public function receipt(Request $request, OrderService $orders, string $tenant, Order $order): RedirectResponse
    {
        abort_unless($this->canView($order), 403);

        $data = $request->validate([
            'receipt' => ['required', 'file', 'mimes:jpg,jpeg,png,webp,pdf', 'max:4096'],
            'reference' => ['nullable', 'string', 'max:100'],
        ]);

        try {
            $orders->attachProof($order, $data['receipt'], $data['reference'] ?? null);
        } catch (BusinessException $e) {
            return back()->with('error', $e->getMessage());
        }

        return back()->with('success', 'Receipt received. The shop will confirm your payment shortly.');
    }

    protected function canView(Order $order): bool
    {
        $customer = auth('customer')->user();

        return ($customer && $customer->id === $order->customer_id)
            || in_array($order->id, (array) session('store.orders.'.tenant_id(), []), true);
    }

    protected function remember(Order $order): void
    {
        $ids = (array) session('store.orders.'.tenant_id(), []);

        if (! in_array($order->id, $ids, true)) {
            session()->push('store.orders.'.tenant_id(), $order->id);
        }
    }
}
