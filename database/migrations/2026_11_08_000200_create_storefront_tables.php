<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Online store: per-shop storefront, colour images, size charts, orders,
 * coupons, wishlists, reviews and home page banners.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('tenants', function (Blueprint $table) {
            $table->boolean('store_enabled')->default(false)->after('settings');   // owner switch
            $table->boolean('store_blocked')->default(false)->after('store_enabled'); // super admin switch
        });

        Schema::table('categories', function (Blueprint $table) {
            $table->string('image')->nullable()->after('slug');
        });

        Schema::table('products', function (Blueprint $table) {
            $table->string('slug')->nullable()->after('name');
            $table->decimal('compare_at_price', 14, 2)->nullable()->after('base_price');
            $table->boolean('show_online')->default(true)->after('status');
            $table->boolean('is_featured')->default(false)->after('show_online');
            $table->string('size_chart')->nullable()->after('image');
        });

        // Slugs for products that already exist.
        DB::table('products')->orderBy('id')->select('id', 'tenant_id', 'name')->each(function ($product) {
            $base = Str::slug($product->name) ?: 'product';
            $slug = $base;
            $i = 2;

            while (DB::table('products')->where('tenant_id', $product->tenant_id)->where('slug', $slug)->exists()) {
                $slug = $base.'-'.$i++;
            }

            DB::table('products')->where('id', $product->id)->update(['slug' => $slug]);
        });

        Schema::table('products', function (Blueprint $table) {
            $table->unique(['tenant_id', 'slug']);
        });

        Schema::create('product_color_images', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('color_id')->constrained()->cascadeOnDelete();
            $table->string('image');
            $table->timestamps();

            $table->unique(['product_id', 'color_id']);
        });

        Schema::table('sales', function (Blueprint $table) {
            $table->decimal('shipping_fee', 14, 2)->default(0)->after('tax');
            $table->string('channel', 20)->default('pos')->after('payment_method'); // pos | online
        });

        Schema::create('store_banners', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('image');
            $table->string('heading', 120);
            $table->string('subheading', 255)->nullable();
            $table->string('button_text', 40)->nullable();
            $table->string('link')->nullable();
            $table->unsignedSmallInteger('sort_order')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->index(['tenant_id', 'is_active', 'sort_order']);
        });

        Schema::create('coupons', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('code', 40);
            $table->string('type', 10)->default('percent'); // percent | fixed
            $table->decimal('value', 14, 2);
            $table->decimal('min_order', 14, 2)->default(0);
            $table->decimal('max_discount', 14, 2)->nullable();
            $table->date('starts_at')->nullable();
            $table->date('expires_at')->nullable();
            $table->unsignedInteger('usage_limit')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->boolean('is_active')->default(true);
            $table->timestamps();

            $table->unique(['tenant_id', 'code']);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->string('order_number', 40);
            $table->foreignId('customer_id')->constrained()->restrictOnDelete();
            $table->foreignId('sale_id')->nullable()->constrained()->nullOnDelete();
            $table->foreignId('branch_id')->nullable()->constrained()->nullOnDelete(); // fulfilment branch
            $table->string('status', 20)->default('pending');       // pending | confirmed | shipped | delivered | cancelled
            $table->string('payment_method', 20)->default('cod');   // cod | bank | wallet
            $table->foreignId('payment_account_id')->nullable()->constrained()->nullOnDelete();
            $table->string('payment_status', 20)->default('unpaid'); // unpaid | awaiting | paid | refunded
            $table->string('payment_proof')->nullable();
            $table->string('payment_reference', 100)->nullable();
            $table->string('customer_name', 120);
            $table->string('phone', 30);
            $table->string('email')->nullable();
            $table->string('address', 500);
            $table->string('city', 100);
            $table->string('notes', 500)->nullable();
            $table->decimal('subtotal', 14, 2);
            $table->decimal('discount', 14, 2)->default(0);
            $table->foreignId('coupon_id')->nullable()->constrained()->nullOnDelete();
            $table->string('coupon_code', 40)->nullable();
            $table->decimal('tax_rate', 5, 2)->default(0);
            $table->decimal('tax', 14, 2)->default(0);
            $table->decimal('shipping_fee', 14, 2)->default(0);
            $table->decimal('total', 14, 2);
            $table->string('courier', 60)->nullable();
            $table->string('tracking_number', 80)->nullable();
            $table->timestamp('confirmed_at')->nullable();
            $table->timestamp('shipped_at')->nullable();
            $table->timestamp('delivered_at')->nullable();
            $table->timestamp('cancelled_at')->nullable();
            $table->string('cancel_reason')->nullable();
            $table->timestamp('seen_at')->nullable(); // first opened by staff (alert bell)
            $table->foreignId('handled_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->unique(['tenant_id', 'order_number']);
            $table->index(['tenant_id', 'status', 'created_at']);
            $table->index(['tenant_id', 'phone']);
            $table->index(['tenant_id', 'customer_id']);
        });

        Schema::create('order_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('order_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_variant_id')->constrained()->restrictOnDelete();
            $table->string('product_name');
            $table->string('variant_name', 120);
            $table->unsignedInteger('quantity');
            $table->decimal('unit_price', 14, 2);
            $table->decimal('total', 14, 2);

            $table->index(['product_variant_id', 'order_id']);
        });

        Schema::create('wishlists', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->timestamps();

            $table->unique(['customer_id', 'product_id']);
        });

        Schema::create('product_reviews', function (Blueprint $table) {
            $table->id();
            $table->foreignId('tenant_id')->constrained()->cascadeOnDelete();
            $table->foreignId('product_id')->constrained()->cascadeOnDelete();
            $table->foreignId('customer_id')->constrained()->cascadeOnDelete();
            $table->unsignedTinyInteger('rating');
            $table->string('title', 120)->nullable();
            $table->text('body')->nullable();
            $table->string('status', 20)->default('pending'); // pending | approved | rejected
            $table->timestamps();

            $table->unique(['customer_id', 'product_id']);
            $table->index(['tenant_id', 'product_id', 'status']);
            $table->index(['tenant_id', 'status']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('product_reviews');
        Schema::dropIfExists('wishlists');
        Schema::dropIfExists('order_items');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('coupons');
        Schema::dropIfExists('store_banners');
        Schema::dropIfExists('product_color_images');

        Schema::table('sales', fn (Blueprint $table) => $table->dropColumn(['shipping_fee', 'channel']));

        Schema::table('products', function (Blueprint $table) {
            $table->dropUnique(['tenant_id', 'slug']);
            $table->dropColumn(['slug', 'compare_at_price', 'show_online', 'is_featured', 'size_chart']);
        });

        Schema::table('categories', fn (Blueprint $table) => $table->dropColumn('image'));
        Schema::table('tenants', fn (Blueprint $table) => $table->dropColumn(['store_enabled', 'store_blocked']));
    }
};
