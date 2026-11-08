<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Services\Store\StoreCatalog;
use App\Services\Store\WishlistService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

class WishlistController extends Controller
{
    public function __construct(protected WishlistService $wishlist) {}

    public function index(StoreCatalog $catalog): View
    {
        $ids = $this->wishlist->ids();
        $products = $ids
            ? $catalog->cards()->whereIn('products.id', $ids)->get()->sortBy(fn ($p) => array_search($p->id, $ids))->values()
            : collect();

        return view('store.pages.wishlist', ['products' => $products]);
    }

    public function toggle(Request $request, string $tenant, int $product): JsonResponse|RedirectResponse
    {
        $saved = $this->wishlist->toggle($product);
        $message = $saved ? 'Saved to your wishlist.' : 'Removed from your wishlist.';

        return $request->expectsJson()
            ? response()->json(['saved' => $saved, 'count' => $this->wishlist->count(), 'message' => $message])
            : back()->with('success', $message);
    }
}
