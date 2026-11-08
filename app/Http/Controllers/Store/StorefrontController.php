<?php

namespace App\Http\Controllers\Store;

use App\Http\Controllers\Controller;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductReview;
use App\Models\Sale;
use App\Models\StoreBanner;
use App\Services\Store\StoreCatalog;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\View\View;

/** Public catalogue pages of a shop's online store. */
class StorefrontController extends Controller
{
    public function __construct(protected StoreCatalog $catalog) {}

    public function home(): View
    {
        return view('store.pages.home', [
            'banners' => StoreBanner::query()->active()->get(),
            'newArrivals' => $this->catalog->newArrivals(8),
            'bestSellers' => $this->catalog->bestSellers(8),
            'onSale' => $this->catalog->onSale(8),
        ]);
    }

    public function shop(Request $request): View
    {
        return $this->listing($request, null);
    }

    public function category(Request $request, string $tenant, Category $category): View
    {
        abort_unless($category->products()->online()->exists(), 404);

        return $this->listing($request, $category);
    }

    public function product(string $tenant, string $slug): View
    {
        $product = $this->catalog->product($slug);
        $customer = auth('customer')->user();

        $reviews = ProductReview::query()->approved()->where('product_id', $product->id)
            ->with('customer:id,name')->latest()->limit(20)->get();

        $myReview = $customer ? ProductReview::query()->where('product_id', $product->id)->where('customer_id', $customer->id)->first() : null;
        $canReview = $customer && Sale::query()
            ->where('customer_id', $customer->id)
            ->whereHas('items.variant', fn ($q) => $q->where('product_id', $product->id))
            ->exists();

        return view('store.pages.product', [
            'product' => $product,
            'reviews' => $reviews,
            'myReview' => $myReview,
            'canReview' => $canReview,
            'related' => $this->catalog->related($product, 4),
        ]);
    }

    public function review(Request $request, string $tenant, Product $product): RedirectResponse
    {
        $customer = auth('customer')->user();
        abort_unless($product->status === 'active' && $product->show_online, 404);

        if (! $customer) {
            return redirect()->route('portal.login')->with('error', 'Sign in to review a product you bought.');
        }

        $bought = Sale::query()->where('customer_id', $customer->id)
            ->whereHas('items.variant', fn ($q) => $q->where('product_id', $product->id))->exists();

        abort_unless($bought, 403, 'Only customers who bought this product can review it.');

        $data = $request->validate([
            'rating' => ['required', 'integer', 'between:1,5'],
            'title' => ['nullable', 'string', 'max:120'],
            'body' => ['nullable', 'string', 'max:2000'],
        ]);

        ProductReview::query()->updateOrCreate(
            ['product_id' => $product->id, 'customer_id' => $customer->id],
            $data + ['status' => 'pending'],
        );

        return redirect()->to(route('store.product', $product->slug).'#reviews')
            ->with('success', 'Thanks! Your review will appear once the shop approves it.');
    }

    protected function listing(Request $request, ?Category $category): View
    {
        $filters = $request->only(['q', 'sizes', 'colors', 'min', 'max', 'sale', 'in_stock', 'sort']);
        $filters['category'] = $category?->slug;

        return view('store.pages.listing', [
            'category' => $category,
            'products' => $this->catalog->listing($filters),
            'facets' => $this->catalog->facets(),
            'filters' => $filters,
            'sorts' => StoreCatalog::SORTS,
        ]);
    }
}
