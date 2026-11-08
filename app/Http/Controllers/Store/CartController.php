<?php

namespace App\Http\Controllers\Store;

use App\Exceptions\BusinessException;
use App\Http\Controllers\Controller;
use App\Services\Store\Cart;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/**
 * Bag endpoints. Each action answers JSON (bag count + refreshed drawer HTML)
 * for script requests and a redirect for plain form posts, so the store keeps
 * working without JavaScript.
 */
class CartController extends Controller
{
    public function __construct(protected Cart $cart) {}

    public function show(): View
    {
        return view('store.pages.cart', ['lines' => $this->cart->lines(), 'summary' => $this->cart->summary()]);
    }

    public function drawer(): JsonResponse
    {
        return $this->json();
    }

    public function add(Request $request): JsonResponse|RedirectResponse
    {
        $data = $request->validate([
            'variant_id' => ['required', 'integer'],
            'quantity' => ['nullable', 'integer', 'min:1', 'max:'.Cart::MAX_QTY],
        ]);

        return $this->attempt($request, function () use ($data) {
            $this->cart->add((int) $data['variant_id'], (int) ($data['quantity'] ?? 1));

            return 'Added to your bag.';
        }, open: true);
    }

    public function update(Request $request, string $tenant, int $variant): JsonResponse|RedirectResponse
    {
        $quantity = (int) $request->validate(['quantity' => ['required', 'integer', 'min:0', 'max:'.Cart::MAX_QTY]])['quantity'];

        return $this->attempt($request, function () use ($variant, $quantity) {
            $this->cart->update($variant, $quantity);

            return $quantity > 0 ? null : 'Removed from your bag.';
        });
    }

    public function remove(Request $request, string $tenant, int $variant): JsonResponse|RedirectResponse
    {
        return $this->attempt($request, function () use ($variant) {
            $this->cart->remove($variant);

            return 'Removed from your bag.';
        });
    }

    public function applyCoupon(Request $request): JsonResponse|RedirectResponse
    {
        $code = (string) $request->validate(['code' => ['required', 'string', 'max:40']])['code'];

        return $this->attempt($request, function () use ($code) {
            $coupon = $this->cart->applyCoupon($code);

            return "{$coupon->code} applied: {$coupon->label}.";
        });
    }

    public function removeCoupon(Request $request): JsonResponse|RedirectResponse
    {
        return $this->attempt($request, function () {
            $this->cart->removeCoupon();

            return 'Discount code removed.';
        });
    }

    /* ------------------------------------------------------------------ */

    protected function attempt(Request $request, \Closure $action, bool $open = false): JsonResponse|RedirectResponse
    {
        try {
            $message = $action();
        } catch (BusinessException $e) {
            return $request->expectsJson()
                ? $this->json(['message' => $e->getMessage(), 'error' => true], 422)
                : back()->withInput()->with('error', $e->getMessage());
        }

        return $request->expectsJson()
            ? $this->json(['message' => $message, 'open' => $open])
            : back()->with('success', $message);
    }

    protected function json(array $extra = [], int $status = 200): JsonResponse
    {
        return response()->json($extra + [
            'count' => $this->cart->count(),
            'html' => view('store.partials.cart-drawer', [
                'lines' => $this->cart->lines(),
                'summary' => $this->cart->summary(),
            ])->render(),
        ], $status);
    }
}
